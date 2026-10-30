<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use Illuminate\Support\Facades\DB;

// Reconciliation and aging when parties use more than one posting profile (SL-FR-02, SL-FR-08, SL-FR-09), the
// ground for a route that names its own profile (ADR-0015). The helpers are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->receivables = docAccountId('1121');
    $this->relatedReceivables = docAccountId('1123');
    $this->payables = docAccountId('2110');
    $this->bank = docAccountId('1112');
    $this->sales = docAccountId('4110');

    foreach ([$this->receivables, $this->relatedReceivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    foreach (['Third parties' => $this->receivables, 'Related parties' => $this->relatedReceivables] as $name => $account) {
        $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => $name])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $account])->assertOk();
        $profiles[$name] = $profile;
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->related = docParty(['name' => 'Budi Berelasi', 'posting_profile_id' => $profiles['Related parties']]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    docType('credit_sale', docInvoiceBody());
    docType('receipt', docSettlingBody());
});

function runReconciliation(): array
{
    return collect(test()->actingAs(test()->admin)->postJson(test()->url.'/reconciliations', ['as_of' => '2026-12-31'])->assertCreated()->json('data'))->keyBy('account_code')->all();
}

describe('two posting profiles on the same side of the ledger', function () {
    it('reconciles each control account on its own, before and after settlements', function () {
        $invoice = docOpenInvoice('1000000');
        $loan = docOpenInvoice('300000', $this->related);
        docPosted(docReceipt('100000', [['open_item_id' => $loan, 'amount' => '100000']], $this->related)->assertCreated()->json('data.id'));
        docPosted(docReceipt('400000', [['open_item_id' => $invoice, 'amount' => '400000']])->assertCreated()->json('data.id'));

        $runs = runReconciliation();

        expect($runs['1121'])->toMatchArray(['ledger_balance' => '600000.000000', 'detail_total' => '600000.000000', 'difference' => '0.000000', 'status' => 'balanced'])
            ->and($runs['1123'])->toMatchArray(['ledger_balance' => '200000.000000', 'detail_total' => '200000.000000', 'difference' => '0.000000', 'status' => 'balanced']);
    });

    it('shows a difference only on the account that has it', function () {
        docOpenInvoice('1000000');
        docOpenInvoice('300000', $this->related);
        $this->actingAs($this->admin)->putJson("{$this->url}/ledger-policy", [
            'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null,
            'source_override_permission' => 'journals.override-source', 'fx_rounding_tolerance' => '0',
        ])->assertOk();
        app(PostingService::class)->post(new PostJournal(
            companyId: $this->companyId, accountingDate: '2026-06-10', description: 'Manual', source: JournalSource::Entry,
            lines: [new PostJournalLine($this->relatedReceivables, '50000', '0'), new PostJournalLine($this->sales, '0', '50000')],
            postedBy: $this->admin->id, actorPermissions: ['journals.override-source'], sourceOverrideReason: 'Koreksi',
        ));

        $runs = runReconciliation();

        expect($runs['1121']['difference'])->toBe('0.000000')
            ->and($runs['1123'])->toMatchArray(['ledger_balance' => '350000.000000', 'detail_total' => '300000.000000', 'difference' => '50000.000000', 'status' => 'difference']);
    });

    it('ages receivables of both profiles together by role, each item with its own party', function () {
        docOpenInvoice('1000000');
        docOpenInvoice('300000', $this->related);

        $report = $this->actingAs($this->admin)->getJson("{$this->url}/reports/aging?".http_build_query(['as_of' => '2026-12-31', 'control_role' => 'receivables_control']))->assertOk()->json('data');

        expect($report['totals']['total'])->toBe('1300000.000000')
            ->and(array_column($report['parties'], 'party_name'))->toBe(['Budi Berelasi', 'PT Maju Jaya'])
            ->and(DB::table('sl_documents')->whereNotNull('control_account_id')->count())->toBe(0);
    });
});
