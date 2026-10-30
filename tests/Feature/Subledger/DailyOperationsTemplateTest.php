<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// The `daily-operations` transaction type template (TE-FR-01): types an admin can fill in without choosing
// debit and credit. The helpers are those of DocumentTest, AllocationTest and RealizedDifferenceTest.

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
    $this->pettyCash = docAccountId('1111');
    $this->expense = docAccountId('6220');
    $this->sales = docAccountId('4110');

    foreach ([$this->receivables, $this->payables, $this->bank, $this->pettyCash] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->supplier = docParty(['name' => 'UD Pemasok', 'roles' => ['supplier']]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
    $this->pettyCashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $this->pettyCash,
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/import-template", [
        'template' => 'daily-operations', 'confirmed' => true, 'keys' => ['customer_receipt', 'supplier_bill', 'supplier_payment', 'cash_transfer', 'party_loan', 'loan_received'],
    ])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/import-template", [
        'template' => 'general', 'confirmed' => true, 'keys' => ['credit_sale'],
    ])->assertCreated();
});

function openSaleOf(string $total): string
{
    docPosted(docEntry('credit_sale', ['customer' => test()->customer, 'total' => $total, 'revenue_account' => test()->sales])->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

describe('a customer receipt', function () {
    it('settles the invoice it names and posts the bank against the receivable', function () {
        $invoice = openSaleOf('1000000');
        $posted = docPosted(docEntry('customer_receipt', ['customer' => $this->customer, 'bank' => $this->cashAccount, 'total' => '1000000', 'allocations' => [['open_item_id' => $invoice, 'amount' => '1000000']]])->assertCreated()->json('data.id'));

        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->get();

        expect(DB::table('sl_open_items')->where('id', $invoice)->value('status'))->toBe('settled')
            ->and($lines)->toHaveCount(2)
            ->and((float) $lines->firstWhere('account_id', $this->bank)->debit)->toBe(1000000.0)
            ->and((float) $lines->firstWhere('account_id', $this->receivables)->credit)->toBe(1000000.0);
    });

    it('stays open as an advance when nothing is allocated', function () {
        docPosted(docEntry('customer_receipt', ['customer' => $this->customer, 'bank' => $this->cashAccount, 'total' => '300000'])->assertCreated()->json('data.id'));

        expect(DB::table('sl_open_items')->where('kind', 'payment')->first())->toMatchArray(['status' => 'open', 'remaining_amount' => '300000.000000']);
    });
});

describe('a supplier bill and its payment', function () {
    it('opens the payable on the bill and settles it with the payment', function () {
        $bill = docPosted(docEntry('supplier_bill', ['supplier' => $this->supplier, 'bill_number' => 'BILL-1', 'total' => '750000', 'expense_account' => $this->expense])->assertCreated()->json('data.id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $bill['journal_id'])->get();
        $open = DB::table('sl_open_items')->where('party_id', $this->supplier)->first();

        expect((float) $lines->firstWhere('account_id', $this->expense)->debit)->toBe(750000.0)
            ->and((float) $lines->firstWhere('account_id', $this->payables)->credit)->toBe(750000.0)
            ->and($open)->toMatchArray(['kind' => 'invoice', 'control_role' => 'payables_control', 'remaining_amount' => '750000.000000']);

        $paid = docPosted(docEntry('supplier_payment', ['supplier' => $this->supplier, 'bank' => $this->cashAccount, 'total' => '750000', 'allocations' => [['open_item_id' => $open->id, 'amount' => '750000']]])->assertCreated()->json('data.id'));
        $paidLines = DB::table('gl_journal_lines')->where('journal_id', $paid['journal_id'])->get();

        expect(DB::table('sl_open_items')->where('id', $open->id)->value('status'))->toBe('settled')
            ->and((float) $paidLines->firstWhere('account_id', $this->payables)->debit)->toBe(750000.0)
            ->and((float) $paidLines->firstWhere('account_id', $this->bank)->credit)->toBe(750000.0);
    });

    it('does not ask the role of the party, which a transaction type may restrict instead (ADR-0015)', function () {
        $id = docEntry('supplier_bill', ['supplier' => $this->customer, 'total' => '1000', 'expense_account' => $this->expense])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertOk();
    });
});

describe('a cash transfer', function () {
    it('moves the money from one cash account to the other', function () {
        $posted = docPosted(docEntry('cash_transfer', ['from' => $this->cashAccount, 'to' => $this->pettyCashAccount, 'amount_out' => '500000', 'amount_in' => '500000'])->assertCreated()->json('data.id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->get();

        expect($lines)->toHaveCount(2)
            ->and((float) $lines->firstWhere('account_id', $this->pettyCash)->debit)->toBe(500000.0)
            ->and((float) $lines->firstWhere('account_id', $this->bank)->credit)->toBe(500000.0);
    });
});

describe('a loan to a party', function () {
    it('raises what the party owes, moves the cash out, and is repaid by a receipt that names it', function () {
        $posted = docPosted(docEntry('party_loan', ['party' => $this->customer, 'bank' => $this->cashAccount, 'loan_number' => 'KSB-1', 'total' => '2000000'])->assertCreated()->json('data.id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->get();
        $loan = DB::table('sl_open_items')->where('kind', 'loan')->first();

        expect($lines)->toHaveCount(2)
            ->and((float) $lines->firstWhere('account_id', $this->receivables)->debit)->toBe(2000000.0)
            ->and((float) $lines->firstWhere('account_id', $this->bank)->credit)->toBe(2000000.0)
            ->and($loan)->toMatchArray(['side' => 'debit', 'document_number' => 'KSB-1', 'remaining_amount' => '2000000.000000'])
            ->and(DB::table('sl_cash_transactions')->value('direction'))->toBe('out');

        docPosted(docEntry('customer_receipt', ['customer' => $this->customer, 'bank' => $this->cashAccount, 'total' => '2000000', 'allocations' => [['open_item_id' => $loan->id, 'amount' => '2000000']]])->assertCreated()->json('data.id'));

        expect(DB::table('sl_open_items')->where('id', $loan->id)->value('status'))->toBe('settled')
            ->and(functionalBalance($this->receivables))->toBe(0.0);
    });

    it('lends to a party whatever role it holds', function () {
        $id = docEntry('party_loan', ['party' => $this->supplier, 'bank' => $this->cashAccount, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertOk();
    });
});

describe('a loan received', function () {
    it('raises what the company owes the lender and moves the cash in', function () {
        $posted = docPosted(docEntry('loan_received', ['party' => $this->supplier, 'bank' => $this->cashAccount, 'total' => '5000000'])->assertCreated()->json('data.id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->get();

        expect((float) $lines->firstWhere('account_id', $this->bank)->debit)->toBe(5000000.0)
            ->and((float) $lines->firstWhere('account_id', $this->payables)->credit)->toBe(5000000.0)
            ->and(DB::table('sl_open_items')->where('kind', 'loan')->value('side'))->toBe('credit')
            ->and(DB::table('sl_cash_transactions')->value('direction'))->toBe('in');
    });
});
