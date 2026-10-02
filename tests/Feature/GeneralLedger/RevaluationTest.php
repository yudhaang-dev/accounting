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

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = revalAccountId($this->companyId, '1112');
    $this->sales = revalAccountId($this->companyId, '4110');
    $this->gain = revalAccountId($this->companyId, '7130');
    $this->loss = revalAccountId($this->companyId, '7230');

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'closing', 'label' => 'Closing rate'])->assertCreated();
    revalRate('2026-03-10', '15000');

    // The bank account holds USD that is revalued; the unrealized gain and loss roles are mapped.
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bank}", ['lock_version' => 1, 'is_revaluable' => true])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/fx_unrealized_gain", ['lock_version' => 0, 'account_id' => $this->gain])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/fx_unrealized_loss", ['lock_version' => 0, 'account_id' => $this->loss])->assertOk();
    revalPolicy('closing', true);

    $this->march = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
});

function revalAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function revalRate(string $date, string $rate): void
{
    test()->actingAs(test()->admin)->postJson(test()->url.'/exchange-rates', [
        'rate_type' => 'closing', 'currency' => 'USD', 'effective_date' => $date, 'rate' => $rate,
    ])->assertCreated();
}

function revalPolicy(?string $rateType, bool $autoReverse, int $version = 0, array $keys = [])
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => $version,
        'soft_closed_transaction_type_keys' => $keys,
        'soft_closed_permission' => null,
        'source_override_permission' => null,
        'fx_rounding_tolerance' => '0',
        'revaluation_rate_type_key' => $rateType,
        'revaluation_auto_reverse' => $autoReverse,
    ]);
}

/** USD received on the bank at the rate effective on 10 March (15000). */
function receiveUsd(string $usd, string $date = '2026-03-15'): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'USD received',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(test()->bank, $usd, '0', currency: 'USD', rateTypeKey: 'closing'),
            new PostJournalLine(test()->sales, '0', (string) ((float) $usd * 15000)),
        ],
    ));
}

function revalue(?string $periodId = null, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/periods/'.($periodId ?? test()->march).'/revaluation');
}

describe('revaluation of foreign currency balances (GL-FR-09, GL-AC-06)', function () {
    it('posts the unrealized gain of USD 1000 from 15000 to 15200 and reverses it on the first day of the next period', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');

        $response = revalue()->assertCreated();
        $adjustment = $response->json('data.adjustments.0');

        expect($adjustment['transaction_balance'])->toBe('1000.00')
            ->and($adjustment['carried'])->toBe('15000000.00')
            ->and($adjustment['revalued'])->toBe('15200000.00')
            ->and($adjustment['adjustment'])->toBe('200000.00');

        $journal = DB::table('gl_journals')->find($response->json('data.journal_id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $journal->id)->orderBy('line_number')->get();

        expect($journal->accounting_date)->toBe('2026-03-31')
            ->and($journal->source)->toBe('system')
            ->and($journal->transaction_type_key)->toBe('system.fx_revaluation')
            ->and($lines)->toHaveCount(2)
            ->and([$lines[0]->account_id, (float) $lines[0]->debit, $lines[0]->currency, (float) $lines[0]->transaction_amount, (float) $lines[0]->exchange_rate])
            ->toBe([$this->bank, 200000.0, 'USD', 0.0, 15200.0])
            ->and([$lines[1]->account_id, (float) $lines[1]->credit, $lines[1]->currency])->toBe([$this->gain, 200000.0, 'IDR']);

        $reversal = DB::table('gl_journals')->find($response->json('data.reversal_journal_id'));

        expect($reversal->accounting_date)->toBe('2026-04-01')
            ->and($reversal->reverses_journal_id)->toBe($journal->id)
            ->and(DB::table('gl_revaluation_runs')->where('id', $response->json('data.id'))->value('journal_id'))->toBe($journal->id);

        // The USD balance did not move; only its carrying value did.
        $row = app(LedgerReader::class)->currencyBalances($this->companyId, '2026-03-31', $this->bank)[0];
        expect([$row->currency, $row->debit, $row->credit, $row->functionalDebit])->toBe(['USD', '1000.00', '0.00', '15200000.00']);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'revaluation.run')->sole();
        expect($audit->properties['journal_id'])->toBe($journal->id);
    });

    it('posts a loss when the closing rate is lower', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '14900');

        $response = revalue()->assertCreated();
        $lines = DB::table('gl_journal_lines')->where('journal_id', $response->json('data.journal_id'))->orderBy('line_number')->get();

        expect([$lines[0]->account_id, (float) $lines[0]->credit])->toBe([$this->bank, 100000.0])
            ->and([$lines[1]->account_id, (float) $lines[1]->debit])->toBe([$this->loss, 100000.0]);
    });

    it('does not double the result when run again, and posts only what changed since', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');
        revalue()->assertCreated();
        $journals = DB::table('gl_journals')->count();

        revalue()->assertOk()->assertJsonPath('data.id', null)->assertJsonPath('data.journal_id', null)->assertJsonPath('data.adjustments', []);
        expect(DB::table('gl_journals')->count())->toBe($journals);

        // USD 100 more on 28 March at 15000: the balance is 1100, worth 16,720,000, carried 16,700,000.
        receiveUsd('100.00', '2026-03-28');
        $response = revalue()->assertCreated();

        expect($response->json('data.adjustments.0.adjustment'))->toBe('20000.00')
            ->and(DB::table('gl_revaluation_runs')->count())->toBe(2);
    });

    it('leaves out control accounts, accounts not marked for revaluation and functional balances', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');

        // A control account takes subledger postings only; it is revalued from its open items (SL-FR-07).
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bank}", ['lock_version' => 2, 'allowed_sources' => ['subledger']])->assertOk();
        revalue()->assertOk()->assertJsonPath('data.id', null);

        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bank}", ['lock_version' => 3, 'allowed_sources' => ['entry', 'subledger', 'system'], 'is_revaluable' => false])->assertOk();
        revalue()->assertOk()->assertJsonPath('data.id', null);

        expect(DB::table('gl_journals')->where('source', 'system')->count())->toBe(0);
    });

    it('keeps the revaluation without a reversal when the policy does not reverse', function () {
        revalPolicy('closing', false, 1)->assertOk();
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');

        revalue()->assertCreated()->assertJsonPath('data.reversal_journal_id', null);

        expect(DB::table('gl_journals')->where('source', 'system')->count())->toBe(1);
    });

    it('asks for what is missing and posts nothing: the rate type, the role mapping and the closing rate (X-AC-03)', function () {
        receiveUsd('1000.00');

        // No closing rate on or before the end of the period newer than 10 March exists, so the old one is used...
        revalPolicy(null, true, 1)->assertOk();
        revalue()->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED')->assertJsonValidationErrors(['revaluation_rate_type_key']);

        // ...and a rate type without any rate for the currency is refused rather than guessed.
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'empty', 'label' => 'No rates'])->assertCreated();
        revalPolicy('empty', true, 2)->assertOk();
        revalue()->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');

        expect(DB::table('gl_journals')->where('source', 'system')->count())->toBe(0);
    });

    it('asks for the unrealized loss role when it is not mapped', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other);
        confirmChartOfAccounts($other, $otherCompany);
        setUpFiscalCalendar($other, $otherCompany);
        $period = (string) DB::table('gl_periods')->where('company_id', $otherCompany)->where('name', '2026-03')->value('id');
        $this->actingAs($other)->putJson("/api/v1/companies/{$otherCompany}/ledger-policy", [
            'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null, 'source_override_permission' => null,
            'fx_rounding_tolerance' => '0', 'revaluation_rate_type_key' => 'closing',
        ])->assertOk();

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/periods/{$period}/revaluation")
            ->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED')->assertJsonValidationErrors(['fx_unrealized_gain']);
    });

    it('is refused for a closed period, and rolled back when the period of the reversal is closed', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');
        $april = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$april}/close")->assertOk();

        revalue()->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
        expect(DB::table('gl_journals')->where('source', 'system')->count())->toBe(0)
            ->and(DB::table('gl_revaluation_runs')->count())->toBe(0);

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->march}/close")->assertOk();
        revalue()->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
    });

    it('can run in a soft-closed period only when the policy lets the system key post there (GL-FR-04)', function () {
        receiveUsd('1000.00');
        revalRate('2026-03-31', '15200');
        revalPolicy('closing', false, 1)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->march}/soft-close")->assertOk();

        revalue()->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');

        revalPolicy('closing', false, 2, ['system.fx_revaluation'])->assertOk();
        revalue()->assertCreated();
    });

    it('needs the periods.revalue permission and a period of the company', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        revalue(as: $viewer)->assertForbidden();
        revalue('01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    });
});
