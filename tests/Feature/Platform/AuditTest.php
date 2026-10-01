<?php

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditEntry;
use App\Platform\Audit\AuditOutcome;
use App\Support\Company\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('records actor, event, company, outcome and before/after values', function () {
    $user = User::factory()->create();
    $companyId = (string) Str::ulid();

    $entry = app(CompanyContext::class)->run($companyId, fn () => app(Audit::class)->record(
        event: 'period.closed',
        description: 'Period closed',
        subject: $user,
        before: ['status' => 'open'],
        after: ['status' => 'closed'],
        properties: ['reason' => 'month-end'],
        actor: $user,
    ));

    $entry->refresh();

    expect(Str::isUlid($entry->id))->toBeTrue()
        ->and($entry->event)->toBe('period.closed')
        ->and($entry->company_id)->toBe($companyId)
        ->and($entry->outcome)->toBe('success')
        ->and($entry->causer_id)->toBe((string) $user->id)
        ->and($entry->causer_type)->toBe($user->getMorphClass())
        ->and($entry->subject_id)->toBe((string) $user->id)
        ->and($entry->attribute_changes->all())->toBe(['old' => ['status' => 'open'], 'attributes' => ['status' => 'closed']])
        ->and($entry->getProperty('reason'))->toBe('month-end')
        ->and($entry->created_at)->not->toBeNull();
});

it('records rejected outcomes and entries without a company or subject', function () {
    $entry = app(Audit::class)->record('entry.rejected', 'Entry rejected', outcome: AuditOutcome::Rejected);

    expect($entry->outcome)->toBe('rejected')
        ->and($entry->company_id)->toBeNull()
        ->and($entry->subject_id)->toBeNull();
});

it('cannot be updated or deleted by the application', function () {
    $entry = app(Audit::class)->record('x', 'x');

    expect(fn () => $entry->update(['description' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('rejects update, delete and truncate at the database level (X-AC-05)', function () {
    $entry = app(Audit::class)->record('x', 'x');

    expect(fn () => DB::transaction(fn () => DB::table('pf_audit_log')->where('id', $entry->id)->update(['description' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('pf_audit_log')->where('id', $entry->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('pf_audit_log')->truncate()))
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditEntry::count())->toBe(1);
});
