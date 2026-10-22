<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $byCode = fn (string $code): string => (string) Account::withoutGlobalScope(CompanyScope::class)
        ->where('company_id', $this->companyId)->where('code', $code)->value('id');

    $this->receivable = $byCode('1112');
    $this->revenue = $byCode('4110');
});

function postWithCounterparty(?string $counterparty): array
{
    $journal = app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: '2026-03-15',
        description: 'Intragroup sale',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(test()->receivable, '1000.00', '0', counterpartyCompanyId: $counterparty),
            new PostJournalLine(test()->revenue, '0', '1000.00'),
        ],
    ));

    return [$journal->id, $journal];
}

describe('counterparty company on a journal line (GL-FR-15)', function () {
    it('stores the reference as given and shows it on the journal', function () {
        $other = (string) Str::ulid();

        [$journalId] = postWithCounterparty($other);

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)->where('journal_id', $journalId)->orderBy('line_number')->get();

        expect($lines[0]->counterparty_company_id)->toBe($other)
            ->and($lines[1]->counterparty_company_id)->toBeNull();

        $this->actingAs($this->admin)->getJson("{$this->url}/journals/{$journalId}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.counterparty_company_id', $other)
            ->assertJsonPath('data.lines.1.counterparty_company_id', null);
    });

    it('leaves lines without a counterparty as they were', function () {
        [$journalId] = postWithCounterparty(null);

        expect(JournalLine::withoutGlobalScope(CompanyScope::class)->where('journal_id', $journalId)->whereNotNull('counterparty_company_id')->count())->toBe(0);
    });

    it('refuses a counterparty that is not a company ID or is the company itself', function () {
        expect(fn () => postWithCounterparty('not-a-ulid'))->toThrow(ValidationException::class);
        expect(fn () => postWithCounterparty($this->companyId))->toThrow(ValidationException::class);
    });

    it('carries the counterparty onto the reversal', function () {
        $other = (string) Str::ulid();
        [$journalId] = postWithCounterparty($other);

        $reversal = app(PostingService::class)->reverse(new ReverseJournal(
            companyId: $this->companyId,
            journalId: $journalId,
            accountingDate: '2026-04-02',
        ));

        expect(JournalLine::withoutGlobalScope(CompanyScope::class)->where('journal_id', $reversal->id)->where('line_number', 1)->value('counterparty_company_id'))->toBe($other);
    });
});
