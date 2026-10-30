<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// A payment whose currency differs from the currency of its cash account (SL-FR-03, TE-FR-06). The helpers
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
    $this->usdBankAccount = docAccountId('1115');
    $this->sales = docAccountId('4110');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');
    $this->expense = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank, $this->usdBankAccount] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);
    mapFxRoles();

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'spot', 'label' => 'Spot'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", ['rate_type' => 'spot', 'currency' => 'USD', 'effective_date' => '2026-03-01', 'rate' => '15500'])->assertCreated();

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->supplier = docParty(['name' => 'UD Pemasok', 'roles' => ['supplier']]);
    $this->idrBank = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA IDR', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '111', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
    $this->usdBank = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA USD', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '222', 'currency' => 'USD', 'account_id' => $this->usdBankAccount,
    ])->assertCreated()->json('data.id');

    // The invoice types take their rate from the entry; the payment types come from the template and take it from the table.
    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('usd_sale', docInvoiceBody($usd));
    docType('usd_receipt', docSettlingBody($usd));

    $import = fn (string $template, array $keys) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/import-template", ['template' => $template, 'confirmed' => true, 'keys' => $keys])->assertCreated();
    $import('daily-operations', ['customer_receipt_cross_currency', 'supplier_bill', 'supplier_payment_cross_currency']);
    $import('general', ['credit_sale']);
});

function idrInvoice(string $total): string
{
    docPosted(docEntry('credit_sale', ['customer' => test()->customer, 'total' => $total, 'revenue_account' => test()->sales])->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->where('currency', 'IDR')->orderByDesc('id')->value('id');
}

function crossReceipt(string $invoice, string $total, string $cashAmount, string $bank, array $overrides = [])
{
    return docEntry('customer_receipt_cross_currency', ['customer' => test()->customer, 'bank' => $bank, 'total' => $total, 'cash_amount' => $cashAmount, 'allocations' => [['open_item_id' => $invoice, 'amount' => $total]]], $overrides);
}

describe('a USD invoice paid into an IDR account', function () {
    it('books the money the account received and the exchange difference against the invoice', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $posted = docPosted(crossReceipt($invoice, '1000', '15500000', $this->idrBank, ['currency' => 'USD'])->assertCreated()->json('data.id'));

        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->get();
        $bank = $lines->firstWhere('account_id', $this->bank);
        $control = $lines->where('account_id', $this->receivables)->firstWhere('transaction_amount', '1000.000000');

        expect($bank)->toMatchArray(['currency' => 'IDR', 'debit' => '15500000.000000', 'transaction_amount' => '15500000.000000'])
            ->and($control)->toMatchArray(['currency' => 'USD', 'credit' => '15500000.000000'])
            ->and(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
            ->and(DB::table('sl_open_items')->where('id', $invoice)->value('status'))->toBe('settled')
            ->and(DB::table('sl_allocations')->value('realized_difference'))->toBe('500000.000000')
            ->and(DB::table('sl_cash_transactions')->first())->toMatchArray(['direction' => 'in', 'currency' => 'IDR', 'amount' => '15500000.000000'])
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->gain))->toBe(-500000.0);
    });

    it('books what the bank kept back as an exchange difference of its own', function () {
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(crossReceipt($invoice, '1000', '15400000', $this->idrBank, ['currency' => 'USD'])->assertCreated()->json('data.id'));

        // 500,000 gained against the invoice, 100,000 lost between the payment and the bank.
        expect(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->gain))->toBe(-500000.0)
            ->and(functionalBalance($this->loss))->toBe(100000.0)
            ->and(DB::table('sl_cash_transactions')->value('amount'))->toBe('15400000.000000');
    });

    it('still refuses a receipt that uses one amount for both the invoice and the account', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $id = docEntry('usd_receipt', ['party' => $this->customer, 'bank' => $this->idrBank, 'total' => '1000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '1000']]], ['currency' => 'USD', 'exchange_rate' => '15500'])
            ->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertStatus(422)->assertJsonValidationErrors(['lines']);
    });
});

describe('an IDR invoice paid into a USD account', function () {
    it('takes the rate of the account from the table, and its amount with the decimals of its own currency', function () {
        $invoice = idrInvoice('15503875');
        $posted = docPosted(crossReceipt($invoice, '15503875', '1000.25', $this->usdBank)->assertCreated()->json('data.id'));

        $bank = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->where('account_id', $this->usdBankAccount)->first();

        expect($bank)->toMatchArray(['currency' => 'USD', 'transaction_amount' => '1000.250000', 'debit' => '15503875.000000'])
            ->and(DB::table('sl_open_items')->where('id', $invoice)->value('status'))->toBe('settled')
            ->and(DB::table('sl_cash_transactions')->first())->toMatchArray(['currency' => 'USD', 'amount' => '1000.250000'])
            ->and(functionalBalance($this->receivables))->toBe(0.0);
    });
});

describe('a supplier bill paid from a USD account', function () {
    it('credits the account in its own currency and settles the bill', function () {
        docPosted(docEntry('supplier_bill', ['supplier' => $this->supplier, 'bill_number' => 'BILL-1', 'total' => '15500000', 'expense_account' => $this->expense])->assertCreated()->json('data.id'));
        $bill = (string) DB::table('sl_open_items')->where('party_id', $this->supplier)->value('id');

        $posted = docPosted(docEntry('supplier_payment_cross_currency', ['supplier' => $this->supplier, 'bank' => $this->usdBank, 'total' => '15500000', 'cash_amount' => '1000', 'allocations' => [['open_item_id' => $bill, 'amount' => '15500000']]])->assertCreated()->json('data.id'));

        $bank = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->where('account_id', $this->usdBankAccount)->first();

        expect($bank)->toMatchArray(['currency' => 'USD', 'transaction_amount' => '1000.000000', 'credit' => '15500000.000000'])
            ->and(DB::table('sl_open_items')->where('id', $bill)->value('status'))->toBe('settled')
            ->and(DB::table('sl_cash_transactions')->where('direction', 'out')->first())->toMatchArray(['currency' => 'USD', 'amount' => '1000.000000'])
            ->and(functionalBalance($this->payables))->toBe(0.0);
    });
});
