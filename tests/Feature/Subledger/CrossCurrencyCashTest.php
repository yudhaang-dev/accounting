<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// A receipt whose currency differs from the currency of its cash account (SL-FR-03, TE-FR-06). The helpers
// are those of DocumentTest, AllocationTest and RealizedDifferenceTest.

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
    $this->sales = docAccountId('4110');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);
    mapFxRoles();

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->idrBank = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA IDR', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '111', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('usd_sale', docInvoiceBody($usd));
    docType('usd_receipt', docSettlingBody($usd));
});

// Not supported yet: the cash line of a receipt takes the currency of the entry, not of the account, so the
// receipt is refused at submit and nothing is posted. Only transfers follow the currency of their accounts.
it('refuses at submit a USD receipt into an IDR bank account and posts nothing', function () {
    $invoice = openUsdInvoice('1000', '15000');
    $journals = DB::table('gl_journals')->count();

    $id = docEntry('usd_receipt', ['party' => $this->customer, 'bank' => $this->idrBank, 'total' => '1000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '1000']]], ['currency' => 'USD', 'exchange_rate' => '15500'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    docAct($this->preparer, $id, 'submit')->assertStatus(422)->assertJsonValidationErrors(['lines']);

    expect(DB::table('gl_journals')->count())->toBe($journals)
        ->and(DB::table('sl_allocations')->count())->toBe(0);
});
