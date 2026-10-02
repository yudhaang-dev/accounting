<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = closingAccountId('1112');
    $this->cash = closingAccountId('1111');
    $this->sales = closingAccountId('4110');
    $this->cost = closingAccountId('5110');
    $this->retained = closingAccountId('3130');

    $this->year = (string) DB::table('gl_fiscal_years')->where('company_id', $this->companyId)->where('name', 'FY2026')->value('id');
});

function closingAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function mapRetainedEarnings(): void
{
    test()->actingAs(test()->admin)->putJson(test()->url.'/account-roles/retained_earnings', ['lock_version' => 0, 'account_id' => test()->retained])->assertOk();
}

/** @param  array<string, string>|null  $dimensions */
function closingJournal(string $date, string $debit, string $credit, string $amount, ?array $dimensions = null): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine($debit, $amount, '0', dimensions: $dimensions),
            new PostJournalLine($credit, '0', $amount, dimensions: $dimensions),
        ],
    ));
}

function closeYear(?string $yearId = null, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/fiscal-years/'.($yearId ?? test()->year).'/close');
}

/** Net debit minus credit per account of everything up to a date. */
function closingBalance(string $accountId, string $asOf = '2026-12-31'): float
{
    return (float) DB::table('gl_journal_lines')->where('company_id', test()->companyId)->where('account_id', $accountId)->where('accounting_date', '<=', $asOf)
        ->selectRaw('coalesce(sum(debit - credit), 0) as net')->value('net');
}

describe('closing a fiscal year (GL-FR-12, GL-FR-13, GL-AC-09)', function () {
    it('posts one balanced closing journal that zeroes income and expense against retained earnings', function () {
        mapRetainedEarnings();
        closingJournal('2026-03-10', $this->bank, $this->sales, '1000000.00');
        closingJournal('2026-08-10', $this->cost, $this->cash, '400000.00');

        $response = closeYear()->assertCreated()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.line_count', 3)
            ->assertJsonPath('data.result', '600000.00')
            ->assertJsonPath('data.retained_earnings_mapping_version', 1);

        $journal = DB::table('gl_journals')->find($response->json('data.journal_id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $journal->id)->get();

        expect($journal->accounting_date)->toBe('2026-12-31')
            ->and($journal->source)->toBe('system')
            ->and($journal->transaction_type_key)->toBe('system.closing')
            ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
            ->and(closingBalance($this->sales))->toBe(0.0)
            ->and(closingBalance($this->cost))->toBe(0.0)
            ->and(closingBalance($this->retained))->toBe(-600000.0)
            ->and(DB::table('gl_journals')->where('transaction_type_key', 'system.closing')->count())->toBe(1);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'fiscal_year.closed')->sole();
        expect($audit->properties['journal_id'])->toBe($journal->id);
    });

    it('closes per dimension set and puts each result in retained earnings in the same set', function () {
        mapRetainedEarnings();

        $type = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types", ['key' => 'branch', 'label' => 'Branch'])->assertCreated()->json('data.id');
        $jakarta = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'JKT', 'name' => 'Jakarta'])->assertCreated()->json('data.id');
        $bandung = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'BDG', 'name' => 'Bandung'])->assertCreated()->json('data.id');

        closingJournal('2026-03-10', $this->bank, $this->sales, '700000.00', ['branch' => $jakarta]);
        closingJournal('2026-03-11', $this->bank, $this->sales, '300000.00', ['branch' => $bandung]);
        closingJournal('2026-04-11', $this->cost, $this->cash, '100000.00', ['branch' => $bandung]);

        $response = closeYear()->assertCreated()->assertJsonPath('data.result', '900000.00');

        $rows = DB::table('gl_journal_lines')->where('journal_id', $response->json('data.journal_id'))
            ->selectRaw('account_id, dimension_set_id, sum(debit - credit) as net')->groupBy('account_id', 'dimension_set_id')->get();

        expect($rows)->toHaveCount(5);

        // Each dimension set nets to zero within the closing journal, and the sets of the result keep their value.
        foreach ($rows->groupBy('dimension_set_id') as $set) {
            expect((float) $set->sum('net'))->toBe(0.0);
        }

        $retained = $rows->where('account_id', $this->retained);
        $values = DB::table('md_dimension_set_items')->whereIn('dimension_set_id', $retained->pluck('dimension_set_id'))->pluck('dimension_value_id', 'dimension_set_id');

        expect($retained)->toHaveCount(2)
            ->and(collect($retained)->mapWithKeys(fn ($row) => [$values[$row->dimension_set_id] => (float) $row->net])->all())
            ->toBe([$jakarta => -700000.0, $bandung => -200000.0]);
    });

    it('carries the balance sheet into the next year and starts income and expense from zero', function () {
        mapRetainedEarnings();
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();

        closingJournal('2026-03-10', $this->bank, $this->sales, '1000000.00');
        closeYear()->assertCreated();

        $rows = collect(app(LedgerReader::class)->trialBalance($this->companyId, '2027-01-01', '2027-01-31'))->keyBy('accountId');

        expect($rows[$this->bank]->openingDebit)->toBe('1000000.00')
            ->and($rows[$this->retained]->openingCredit)->toBe('1000000.00')
            ->and($rows->has($this->sales))->toBeFalse();
    });

    it('answers the same closing when the year is closed again and posts nothing more', function () {
        mapRetainedEarnings();
        closingJournal('2026-03-10', $this->bank, $this->sales, '1000000.00');

        $first = closeYear()->assertCreated();

        closeYear()->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        expect(DB::table('gl_journals')->where('transaction_type_key', 'system.closing')->count())->toBe(1)
            ->and(DB::table('gl_closing_runs')->count())->toBe(1);
    });

    it('closes a year without income or expense without a journal', function () {
        mapRetainedEarnings();
        closingJournal('2026-03-10', $this->bank, $this->retained, '1000000.00');

        closeYear()->assertCreated()->assertJsonPath('data.journal_id', null)->assertJsonPath('data.result', '0.00');

        expect(DB::table('gl_journals')->where('transaction_type_key', 'system.closing')->count())->toBe(0);
    });

    it('is refused while entries dated up to the end of the year are unfinished', function () {
        mapRetainedEarnings();
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
        $preparer = memberWithRoles($this->companyId, 'preparer');

        $this->actingAs($preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-12-20',
            'description' => 'Still a draft',
            'lines' => [['account_id' => $this->bank, 'debit' => '10.00'], ['account_id' => $this->sales, 'credit' => '10.00']],
        ])->assertCreated();

        closeYear()->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonStructure(['errors' => ['entries']]);

        expect(DB::table('gl_closing_runs')->count())->toBe(0);
    });

    it('is refused without a retained earnings mapping, and posts nothing', function () {
        closingJournal('2026-03-10', $this->bank, $this->sales, '1000000.00');

        closeYear()->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED');

        expect(DB::table('gl_journals')->where('transaction_type_key', 'system.closing')->count())->toBe(0)
            ->and(DB::table('gl_closing_runs')->count())->toBe(0);
    });

    it('is refused when the last period does not accept the closing journal', function () {
        mapRetainedEarnings();
        closingJournal('2026-03-10', $this->bank, $this->sales, '1000000.00');

        $december = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-12')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$december}/close")->assertOk();

        closeYear()->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
    });

    it('is refused when the ledger does not balance', function () {
        mapRetainedEarnings();

        // Only a damaged ledger can be unbalanced; the database refuses it, so its check is lifted to damage this one.
        DB::statement('ALTER TABLE gl_journal_lines DISABLE TRIGGER gl_journal_lines_balanced');
        closingJournal('2026-03-10', $this->bank, $this->sales, '1000.00');
        $line = DB::table('gl_journal_lines')->where('account_id', $this->bank)->first();
        DB::table('gl_journal_lines')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $this->companyId, 'journal_id' => $line->journal_id, 'line_number' => 3,
            'account_id' => $this->bank, 'period_id' => $line->period_id, 'accounting_date' => '2026-03-10', 'debit' => '50', 'credit' => '0',
            'currency' => 'IDR', 'transaction_amount' => '50', 'dimension_set_id' => $line->dimension_set_id,
        ]);

        closeYear()->assertUnprocessable()->assertJsonPath('code', 'UNBALANCED_ENTRY');

        expect(DB::table('gl_closing_runs')->count())->toBe(0);
    });

    it('needs the fiscal-years.close permission and a year of the company', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        closeYear(as: $viewer)->assertForbidden();
        closeYear('01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    });
});
