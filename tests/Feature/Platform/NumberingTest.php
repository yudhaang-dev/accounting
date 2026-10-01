<?php

use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->numbering = app(Numbering::class);
    $this->company = (string) Str::ulid();
});

it('issues patterned numbers with prefix, date tokens and padding', function () {
    $this->numbering->define($this->company, 'journal', 'JU/{yyyy}/{mm}/{seq}', 5, ResetPeriod::Monthly);

    expect($this->numbering->next($this->company, 'journal', now()->setDate(2026, 10, 15)))->toBe('JU/2026/10/00001')
        ->and($this->numbering->next($this->company, 'journal', now()->setDate(2026, 10, 31)))->toBe('JU/2026/10/00002');
});

it('restarts the sequence when the reset bucket changes', function () {
    $this->numbering->define($this->company, 'journal', 'JU-{yy}{mm}-{seq}', 3, ResetPeriod::Monthly);

    $this->numbering->next($this->company, 'journal', now()->setDate(2026, 10, 1));
    $this->numbering->next($this->company, 'journal', now()->setDate(2026, 10, 2));

    expect($this->numbering->next($this->company, 'journal', now()->setDate(2026, 11, 1)))->toBe('JU-2611-001')
        ->and($this->numbering->next($this->company, 'journal', now()->setDate(2026, 10, 3)))->toBe('JU-2610-003');
});

it('never resets when the series says never', function () {
    $this->numbering->define($this->company, 'entry', 'E{seq}', 4, ResetPeriod::Never);

    $this->numbering->next($this->company, 'entry', now()->setDate(2026, 12, 31));

    expect($this->numbering->next($this->company, 'entry', now()->setDate(2027, 1, 1)))->toBe('E0002');
});

it('keeps series and companies independent', function () {
    $other = (string) Str::ulid();
    $this->numbering->define($this->company, 'a', 'A{seq}', 1, ResetPeriod::Never);
    $this->numbering->define($this->company, 'b', 'B{seq}', 1, ResetPeriod::Never);
    $this->numbering->define($other, 'a', 'A{seq}', 1, ResetPeriod::Never);

    $this->numbering->next($this->company, 'a', now());
    $this->numbering->next($this->company, 'a', now());

    expect($this->numbering->next($this->company, 'b', now()))->toBe('B1')
        ->and($this->numbering->next($other, 'a', now()))->toBe('A1');
});

it('leaves no gap when the surrounding transaction rolls back', function () {
    $this->numbering->define($this->company, 'journal', 'J{seq}', 3, ResetPeriod::Never);
    $this->numbering->next($this->company, 'journal', now());

    try {
        DB::transaction(function () {
            expect($this->numbering->next($this->company, 'journal', now()))->toBe('J002');

            throw new RuntimeException('posting failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->numbering->next($this->company, 'journal', now()))->toBe('J002');
});

it('fails for an undefined series', function () {
    $this->numbering->next($this->company, 'missing', now());
})->throws(NumberSeriesNotFound::class);

it('rejects patterns that would repeat numbers or are malformed', function (string $pattern, ResetPeriod $reset) {
    $this->numbering->define($this->company, 'x', $pattern, 3, $reset);
})->throws(InvalidArgumentException::class)->with([
    'no sequence' => ['JU/{yyyy}', ResetPeriod::Yearly],
    'two sequences' => ['{seq}-{seq}', ResetPeriod::Never],
    'unknown token' => ['{foo}{seq}', ResetPeriod::Never],
    'monthly without month' => ['{yyyy}-{seq}', ResetPeriod::Monthly],
    'yearly without year' => ['JU-{seq}', ResetPeriod::Yearly],
    'daily without day' => ['{yyyy}{mm}-{seq}', ResetPeriod::Daily],
]);

it('updates an existing definition without touching the counter', function () {
    $this->numbering->define($this->company, 'journal', 'J{seq}', 2, ResetPeriod::Never);
    $this->numbering->next($this->company, 'journal', now());

    $this->numbering->define($this->company, 'journal', 'JU-{seq}', 4, ResetPeriod::Never);

    expect($this->numbering->next($this->company, 'journal', now()))->toBe('JU-0002');
});
