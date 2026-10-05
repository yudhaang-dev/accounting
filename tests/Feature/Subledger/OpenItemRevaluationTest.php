<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Revaluation of the foreign currency open items (SL-FR-07, SL-AC-06). The helpers to build types and
// entries are those of DocumentTest, AllocationTest and RealizedDifferenceTest.

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
    $this->bank = docAccountId('1115');
    $this->sales = docAccountId('4110');
    $this->realizedGain = docAccountId('4130');
    $this->realizedLoss = docAccountId('6220');
    $this->expense = docAccountId('6220');
    $this->unrealizedGain = docAccountId('7130');
    $this->unrealizedLoss = docAccountId('7230');

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'closing', 'label' => 'Closing rate'])->assertCreated();
    foreach ([['2026-03-31', '15500'], ['2026-04-30', '16000']] as [$date, $rate]) {
        $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", ['rate_type' => 'closing', 'currency' => 'USD', 'effective_date' => $date, 'rate' => $rate])->assertCreated();
    }

    foreach (['fx_realized_gain' => $this->realizedGain, 'fx_realized_loss' => $this->realizedLoss, 'fx_unrealized_gain' => $this->unrealizedGain, 'fx_unrealized_loss' => $this->unrealizedLoss] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $profile = $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->supplier = docParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true]);
    $this->usdBank = $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA USD', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'USD', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('usd_sale', docInvoiceBody($usd));
    docType('usd_receipt', docSettlingBody($usd));

    $this->march = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
});

function revaluePolicy(?string $rateType, bool $autoReverse)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null, 'source_override_permission' => null,
        'fx_rounding_tolerance' => '0', 'revaluation_rate_type_key' => $rateType, 'revaluation_auto_reverse' => $autoReverse,
    ])->assertOk();
}

function revalueItems(?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/periods/'.test()->march.'/open-item-revaluation');
}

function usdInvoiceOn(string $total, string $rate, string $date = '2026-03-15'): string
{
    docPosted(docEntry('usd_sale', ['party' => test()->customer, 'total' => $total], ['currency' => 'USD', 'exchange_rate' => $rate, 'accounting_date' => $date])->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

function usdReceiptOn(string $total, string $rate, string $invoice, string $date)
{
    return docPosted(docEntry('usd_receipt', ['party' => test()->customer, 'bank' => test()->usdBank, 'total' => $total, 'allocs' => [['open_item_id' => $invoice, 'amount' => $total]]], ['currency' => 'USD', 'exchange_rate' => $rate, 'accounting_date' => $date])->assertCreated()->json('data.id'));
}

function accountBalance(string $accountId): float
{
    return (float) DB::table('gl_journal_lines')->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')->value('balance');
}

/** The gain of the open item: realized and unrealized together, credit positive. */
function totalGain(): float
{
    return -(accountBalance(test()->realizedGain) + accountBalance(test()->unrealizedGain)) + accountBalance(test()->realizedLoss) + accountBalance(test()->unrealizedLoss);
}

describe('revaluing the open items at the end of a period (SL-FR-07)', function () {
    it('posts the difference to the control account and the unrealized gain, and reverses it the next day when the policy says so', function () {
        revaluePolicy('closing', true);
        $invoice = usdInvoiceOn('1000', '15000');

        $response = revalueItems()->assertCreated();
        $run = $response->json('data');
        $journal = DB::table('gl_journals')->where('id', $run['journal_id'])->first();
        $reversal = DB::table('gl_journals')->where('id', $run['reversal_journal_id'])->first();

        expect($run['adjustments'])->toBe([['open_item_id' => $invoice, 'currency' => 'USD', 'remaining_amount' => '1000.000000', 'carried' => '15000000.000000', 'revalued' => '15500000.000000', 'adjustment' => '500000.000000']])
            ->and($journal)->toMatchArray(['source' => 'subledger', 'transaction_type_key' => 'system.fx_revaluation'])
            ->and(substr($journal->accounting_date, 0, 10))->toBe('2026-03-31')
            ->and(substr($reversal->accounting_date, 0, 10))->toBe('2026-04-01')
            ->and($reversal->reverses_journal_id)->toBe($journal->id)
            // The reversal takes it all back, and the open item never moved.
            ->and(accountBalance($this->unrealizedGain))->toBe(0.0)
            ->and(accountBalance($this->receivables))->toBe(15000000.0)
            ->and(DB::table('sl_open_items')->where('id', $invoice)->value('remaining_functional'))->toBe('15000000.000000');

        $lines = DB::table('gl_journal_lines')->where('journal_id', $run['journal_id'])->orderBy('line_number')->get();

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray(['account_id' => $this->receivables, 'transaction_amount' => '0.000000'])
            ->and((float) $lines[0]->debit)->toBe(500000.0)
            ->and($lines[1]->account_id)->toBe($this->unrealizedGain)
            ->and((float) $lines[1]->credit)->toBe(500000.0);
    });

    it('does not double the result when the period is revalued again', function () {
        revaluePolicy('closing', true);
        usdInvoiceOn('1000', '15000');
        revalueItems()->assertCreated();
        $journals = DB::table('gl_journals')->count();

        revalueItems()->assertOk()->assertJsonPath('data.id', null)->assertJsonPath('data.adjustments', []);

        expect(DB::table('gl_journals')->count())->toBe($journals)->and(DB::table('sl_revaluation_runs')->count())->toBe(1);
    });

    it('posts only what changed when the rate or the open items change before the run again', function () {
        revaluePolicy('closing', true);
        usdInvoiceOn('1000', '15000');
        revalueItems()->assertCreated();
        usdInvoiceOn('200', '15000', '2026-03-20');

        $second = revalueItems()->assertCreated()->json('data.adjustments');

        // Only the new invoice is off: 200 USD at 15,500 against 15,000.
        expect($second)->toHaveCount(1)->and($second[0]['adjustment'])->toBe('100000.000000');
    });

    it('revalues a payable the other way: a liability worth more is a loss', function () {
        revaluePolicy('closing', false);
        docType('usd_bill', docInvoiceBody([
            'allowed_currencies' => ['USD'], 'allow_rate_input' => true,
            'posting_rules' => [['side' => 'credit', 'account' => docControl()], ['side' => 'debit', 'account' => docFixed($this->expense)]],
            'subledger_route' => ['control_role' => 'payables_control'],
        ]));
        docPosted(docEntry('usd_bill', ['party' => $this->supplier, 'total' => '1000'], ['currency' => 'USD', 'exchange_rate' => '15000'])->assertCreated()->json('data.id'));

        revalueItems()->assertCreated()->assertJsonPath('data.reversal_journal_id', null);

        expect(accountBalance($this->unrealizedLoss))->toBe(500000.0)
            ->and(accountBalance($this->payables))->toBe(-15500000.0)
            ->and(DB::table('sl_open_items')->where('kind', 'invoice')->value('remaining_functional'))->toBe('15500000.000000');
    });

    it('leaves out the open items in the functional currency', function () {
        revaluePolicy('closing', true);
        docType('credit_sale', docInvoiceBody());
        docPosted(docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000000'])->assertCreated()->json('data.id'));

        revalueItems()->assertOk()->assertJsonPath('data.id', null);
    });

    it('needs a rate type in the policy, the roles, a closing rate and the permission', function () {
        usdInvoiceOn('1000', '15000');

        revalueItems()->assertStatus(422)->assertJsonPath('code', 'MAPPING_REQUIRED');

        revaluePolicy('closing', true);
        revalueItems($this->preparer)->assertForbidden();

        // February has no closing rate yet.
        $february = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-02')->value('id');
        usdInvoiceOn('100', '15000', '2026-02-10');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$february}/open-item-revaluation")->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
    });

    it('refuses a period with allocations dated after it', function () {
        revaluePolicy('closing', true);
        $invoice = usdInvoiceOn('1000', '15000');
        usdReceiptOn('400', '15800', $invoice, '2026-04-10');

        revalueItems()->assertStatus(422)->assertJsonValidationErrors(['period_id']);
    });
});

describe('the unrealized and the realized difference together (SL-AC-06)', function () {
    it('add up to the difference from the rate of the invoice to the rate of the payment when the revaluation is reversed', function () {
        revaluePolicy('closing', true);
        $invoice = usdInvoiceOn('1000', '15000');
        revalueItems()->assertCreated();

        // Paid in April at 16,000: 1,000,000 from the rate of the invoice, whatever March showed.
        usdReceiptOn('1000', '16000', $invoice, '2026-04-10');

        expect(totalGain())->toBe(1000000.0)
            ->and(accountBalance($this->unrealizedGain))->toBe(0.0)
            ->and(accountBalance($this->receivables))->toBe(0.0);
    });

    it('add up to the same when the revaluation is kept, the realized part starting from the closing rate', function () {
        revaluePolicy('closing', false);
        $invoice = usdInvoiceOn('1000', '15000');
        revalueItems()->assertCreated();

        $posted = usdReceiptOn('1000', '16000', $invoice, '2026-04-10');

        expect(DB::table('sl_allocations')->value('realized_difference'))->toBe('500000.000000')
            ->and(accountBalance($this->unrealizedGain))->toBe(-500000.0)
            ->and(accountBalance($this->realizedGain))->toBe(-500000.0)
            ->and(totalGain())->toBe(1000000.0)
            ->and(accountBalance($this->receivables))->toBe(0.0)
            ->and(DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->count())->toBe(4);
    });
});
