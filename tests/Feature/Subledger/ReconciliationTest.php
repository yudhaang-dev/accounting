<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// Reconciliation of control and cash accounts with their subledger (SL-FR-09, SL-AC-05) and its place in the
// readiness to close a year (GL-AC-09). The helpers to build types and entries are those of DocumentTest,
// AllocationTest and RealizedDifferenceTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->receivables = docAccountId('1121');
    $this->payables = docAccountId('2110');
    $this->bank = docAccountId('1112');
    $this->usdBankAccount = docAccountId('1115');
    $this->sales = docAccountId('4110');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');
    $this->retained = docAccountId('3130');

    foreach ([$this->receivables, $this->payables, $this->bank, $this->usdBankAccount] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/retained_earnings", ['lock_version' => 0, 'account_id' => $this->retained])->assertOk();

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
    $this->usdBank = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA USD', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'USD', 'account_id' => $this->usdBankAccount,
    ])->assertCreated()->json('data.id');

    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('credit_sale', docInvoiceBody());
    docType('receipt', docSettlingBody());
    docType('usd_sale', docInvoiceBody($usd));
    docType('usd_receipt', docSettlingBody($usd));
});

function reconcile(string $asOf = '2026-12-31', ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/reconciliations', ['as_of' => $asOf]);
}

/** The runs of a reconciliation by account code. */
function reconciled(string $asOf = '2026-12-31'): array
{
    return collect(reconcile($asOf)->assertCreated()->json('data'))->keyBy('account_code')->all();
}

/** A manual journal to the receivables control account, which only the override policy lets through. */
function overrideToReceivables(string $amount = '250000'): void
{
    test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null,
        'source_override_permission' => 'journals.override-source', 'fx_rounding_tolerance' => '0',
    ])->assertOk();

    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: '2026-06-10',
        description: 'Manual receivable',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(test()->receivables, $amount, '0'), new PostJournalLine(test()->sales, '0', $amount)],
        postedBy: test()->admin->id,
        actorPermissions: ['journals.override-source'],
        sourceOverrideReason: 'Koreksi manual',
    ));
}

describe('what the subledger holds against the ledger (SL-FR-09)', function () {
    it('balances when everything was posted through the subledger', function () {
        $invoice = docOpenInvoice('1000000');
        docPosted(docReceipt('400000', [['open_item_id' => $invoice, 'amount' => '400000']])->assertCreated()->json('data.id'));
        docPosted(docReceipt('150000')->assertCreated()->json('data.id'));

        $runs = reconciled();

        expect($runs['1121'])->toMatchArray(['kind' => 'control', 'currency' => 'IDR', 'ledger_balance' => '450000.000000', 'detail_total' => '450000.000000', 'difference' => '0.000000', 'status' => 'balanced', 'causes' => []])
            ->and($runs['1112'])->toMatchArray(['kind' => 'cash', 'currency' => 'IDR', 'ledger_balance' => '550000.000000', 'detail_total' => '550000.000000', 'status' => 'balanced'])
            ->and($runs)->toHaveKeys(['1121', '1112', '1115']);
    });

    it('is the same after allocations at other rates, their cancellation and the reversal of documents', function () {
        mapFxRoles();
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1000', '15500', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));
        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');

        expect(reconciled()['1121'])->toMatchArray(['ledger_balance' => '0.000000', 'difference' => '0.000000']);
        expect(reconciled()['1115'])->toMatchArray(['currency' => 'USD', 'ledger_balance' => '1000.000000', 'difference' => '0.000000']);

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/".DB::table('sl_allocations')->value('id').'/cancel', ['reason' => 'Salah faktur'])->assertOk();
        expect(reconciled()['1121']['difference'])->toBe('0.000000');

        reversePosted(journalOfDocument($payment));
        $runs = reconciled();

        expect($runs['1121'])->toMatchArray(['ledger_balance' => '15000000.000000', 'difference' => '0.000000'])
            ->and($runs['1115'])->toMatchArray(['ledger_balance' => '0.000000', 'difference' => '0.000000']);

        // On a date before the reversal the payment still stood.
        expect(reconciled('2026-03-16')['1121']['difference'])->toBe('0.000000');
    });

    it('adds the revaluation that has not been reversed to what the open items hold', function () {
        mapFxRoles();
        openUsdInvoice('1000', '15000');
        $march = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');

        foreach (['unrealized_gain' => $this->gain, 'unrealized_loss' => $this->loss] as $role => $account) {
            mapRoleTo("fx_{$role}", $account);
        }

        $this->actingAs($this->admin)->putJson("{$this->url}/ledger-policy", [
            'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null, 'source_override_permission' => null,
            'fx_rounding_tolerance' => '0', 'revaluation_rate_type_key' => 'closing', 'revaluation_auto_reverse' => false,
        ])->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'closing', 'label' => 'Closing rate'])->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", ['rate_type' => 'closing', 'currency' => 'USD', 'effective_date' => '2026-03-31', 'rate' => '15400'])->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$march}/open-item-revaluation")->assertCreated();

        expect(reconciled('2026-03-31')['1121'])->toMatchArray(['ledger_balance' => '15400000.000000', 'difference' => '0.000000']);
    });

    it('shows an override journal as a difference with the journal that causes it (SL-AC-05)', function () {
        docOpenInvoice('1000000');
        overrideToReceivables('250000');

        $runs = reconciled();

        expect($runs['1121'])->toMatchArray(['ledger_balance' => '1250000.000000', 'detail_total' => '1000000.000000', 'difference' => '250000.000000', 'status' => 'difference'])
            ->and($runs['1121']['causes'])->toHaveCount(1)
            ->and($runs['1121']['causes'][0])->toMatchArray(['source' => 'entry', 'debit' => '250000.000000', 'credit' => '0.000000', 'accounting_date' => '2026-06-10']);

        $this->actingAs($this->admin)->getJson("{$this->url}/reconciliations?filter[status]=difference")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.account_code', '1121');
        $this->actingAs($this->admin)->getJson("{$this->url}/reconciliations/{$runs['1121']['id']}")->assertOk()->assertJsonPath('data.difference', '250000.000000');
    });

    it('needs the permission to run it and to read it', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/reconciliations")->assertOk();
        reconcile('2026-12-31', $viewer)->assertForbidden();
        reconcile('2026-12-31', $this->preparer)->assertForbidden();
    });
});

describe('closing the year with a difference (SL-AC-05, GL-AC-09)', function () {
    beforeEach(function () {
        $this->year = (string) DB::table('gl_fiscal_years')->where('company_id', $this->companyId)->where('name', 'FY2026')->value('id');
        docOpenInvoice('1000000');
        overrideToReceivables('250000');
    });

    it('is refused and the readiness names the check, until the difference is waived with a reason', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years/{$this->year}/closing")->assertOk()
            ->assertJsonPath('data.ready', false)->assertJsonPath('data.checks.reconciliation', ['ok' => false, 'count' => 1]);

        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$this->year}/close")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        expect(DB::table('gl_journals')->where('transaction_type_key', 'system.closing')->count())->toBe(0);

        $run = reconciled()['1121'];

        $this->actingAs($this->admin)->postJson("{$this->url}/reconciliations/{$run['id']}/waive", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($this->approver)->postJson("{$this->url}/reconciliations/{$run['id']}/waive", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->postJson("{$this->url}/reconciliations/{$run['id']}/waive", ['reason' => 'Koreksi manual disetujui auditor'])
            ->assertOk()->assertJsonPath('data.status', 'waived')->assertJsonPath('data.waiver_reason', 'Koreksi manual disetujui auditor')->assertJsonPath('data.waived_by', $this->admin->id);

        $this->actingAs($this->admin)->postJson("{$this->url}/reconciliations/{$run['id']}/waive", ['reason' => 'Lagi'])->assertStatus(409);
        expect(AuditEntry::where('event', 'reconciliation.waived')->where('company_id', $this->companyId)->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years/{$this->year}/closing")->assertJsonPath('data.checks.reconciliation', ['ok' => true, 'count' => 0]);
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$this->year}/close")->assertCreated()->assertJsonPath('data.status', 'closed');
    });

    it('needs a new waiver when the difference changed after the old one', function () {
        $run = reconciled()['1121'];
        $this->actingAs($this->admin)->postJson("{$this->url}/reconciliations/{$run['id']}/waive", ['reason' => 'Koreksi manual'])->assertOk();

        app(PostingService::class)->post(new PostJournal(
            companyId: $this->companyId, accountingDate: '2026-07-10', description: 'Another manual receivable', source: JournalSource::Entry,
            lines: [new PostJournalLine($this->receivables, '10000', '0'), new PostJournalLine($this->sales, '0', '10000')],
            postedBy: $this->admin->id, actorPermissions: ['journals.override-source'], sourceOverrideReason: 'Lagi',
        ));

        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$this->year}/close")->assertStatus(409);
    });

    it('does not keep a balanced account, so there is nothing to waive', function () {
        $balanced = reconciled()['1115'];

        expect($balanced)->toMatchArray(['id' => null, 'status' => 'balanced'])
            ->and(DB::table('sl_reconciliation_runs')->where('status', 'balanced')->count())->toBe(0)
            ->and(DB::table('sl_reconciliation_runs')->where('account_code', '1121')->count())->toBe(1);
    });

    it('keeps an unchanged difference once however often it runs, and again when it changes', function () {
        $first = reconciled()['1121'];
        $this->actingAs($this->admin)->postJson("{$this->url}/reconciliations/{$first['id']}/waive", ['reason' => 'Koreksi manual'])->assertOk();

        $again = reconciled()['1121'];

        expect($again)->toMatchArray(['id' => $first['id'], 'status' => 'waived'])
            ->and(DB::table('sl_reconciliation_runs')->count())->toBe(1)
            // Every run is audited, kept or not.
            ->and(AuditEntry::where('event', 'reconciliation.run')->where('company_id', $this->companyId)->count())->toBe(2);

        app(PostingService::class)->post(new PostJournal(
            companyId: $this->companyId, accountingDate: '2026-07-10', description: 'Another manual receivable', source: JournalSource::Entry,
            lines: [new PostJournalLine($this->receivables, '10000', '0'), new PostJournalLine($this->sales, '0', '10000')],
            postedBy: $this->admin->id, actorPermissions: ['journals.override-source'], sourceOverrideReason: 'Lagi',
        ));
        $changed = reconciled()['1121'];

        expect($changed['id'])->not->toBe($first['id'])
            ->and($changed)->toMatchArray(['status' => 'difference', 'difference' => '260000.000000'])
            ->and(DB::table('sl_reconciliation_runs')->count())->toBe(2);
    });
});
