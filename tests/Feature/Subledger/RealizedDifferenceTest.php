<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Realized exchange differences of allocations in a foreign currency (SL-FR-06, SL-AC-02). The helpers
// to build types and entries are those of DocumentTest and AllocationTest.

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
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');
    $this->expense = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->usdBank = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA USD', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'USD', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
    $this->cashAccount = $this->usdBank;

    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('usd_sale', docInvoiceBody($usd));
    docType('usd_receipt', docSettlingBody($usd));
});

/** The gain account is revenue and the loss account an expense, as the roles need. */
function mapFxRoles(): void
{
    foreach (['fx_realized_gain' => test()->gain, 'fx_realized_loss' => test()->loss] as $role => $account) {
        mapRoleTo("{$role}", $account);
    }
}

function usdInvoice(string $total, string $rate)
{
    return docEntry('usd_sale', ['party' => test()->customer, 'total' => $total], ['currency' => 'USD', 'exchange_rate' => $rate]);
}

function usdReceipt(string $total, string $rate, array $allocations = [])
{
    return docEntry('usd_receipt', array_filter(['party' => test()->customer, 'bank' => test()->usdBank, 'total' => $total, 'allocs' => $allocations ?: null]), ['currency' => 'USD', 'exchange_rate' => $rate]);
}

function openUsdInvoice(string $total = '1000', string $rate = '15000'): string
{
    docPosted(usdInvoice($total, $rate)->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

/** Functional balance of an account over all journals, debit less credit. */
function functionalBalance(string $accountId): float
{
    return (float) DB::table('gl_journal_lines')->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')->value('balance');
}

describe('a payment at another rate than the invoice (SL-AC-02)', function () {
    beforeEach(fn () => mapFxRoles());

    it('closes the invoice and books the realized gain in the journal of the payment', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $posted = docPosted(usdReceipt('1000', '15500', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));

        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->orderBy('line_number')->get();
        $allocation = DB::table('sl_allocations')->first();

        expect(DB::table('sl_open_items')->where('id', $invoice)->first())->toMatchArray(['status' => 'settled', 'remaining_amount' => '0.000000', 'remaining_functional' => '0.000000'])
            ->and(DB::table('sl_open_items')->where('kind', 'payment')->first())->toMatchArray(['status' => 'settled', 'remaining_functional' => '0.000000', 'original_functional' => '15500000.000000'])
            ->and($allocation)->toMatchArray(['functional_amount' => '15000000.000000', 'credit_functional_amount' => '15500000.000000', 'realized_difference' => '500000.000000', 'journal_id' => null])
            // The bank, the receivable at the rate of the payment, its adjustment, and the gain.
            ->and($lines->count())->toBe(4)
            ->and(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->gain))->toBe(-500000.0);

        $gain = $lines->firstWhere('account_id', $this->gain);
        $adjustment = $lines->where('account_id', $this->receivables)->firstWhere('transaction_amount', '0.000000');

        expect((float) $gain->credit)->toBe(500000.0)->and((float) $adjustment->debit)->toBe(500000.0);
    });

    it('books a loss when the rate fell, and leaves the control account at the balance of the open items', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $posted = docPosted(usdReceipt('1000', '14800', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));

        expect(DB::table('sl_allocations')->value('realized_difference'))->toBe('-200000.000000')
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->loss))->toBe(200000.0)
            ->and(DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->count())->toBe(4);
    });

    it('books the difference of the part that is settled and keeps the rest at the rate of the payment', function () {
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1500', '15500', [['open_item_id' => $invoice, 'amount' => '600']])->assertCreated()->json('data.id'));

        $payment = DB::table('sl_open_items')->where('kind', 'payment')->first();

        expect(DB::table('sl_allocations')->first())->toMatchArray(['amount' => '600.000000', 'functional_amount' => '9000000.000000', 'credit_functional_amount' => '9300000.000000', 'realized_difference' => '300000.000000'])
            ->and(DB::table('sl_open_items')->where('id', $invoice)->first())->toMatchArray(['remaining_amount' => '400.000000', 'remaining_functional' => '6000000.000000'])
            ->and($payment)->toMatchArray(['remaining_amount' => '900.000000', 'remaining_functional' => '13950000.000000'])
            // Receivable in the ledger: 15,000,000 - 23,250,000 + 300,000 = -7,950,000, which is 6,000,000 - 13,950,000.
            ->and(functionalBalance($this->receivables))->toBe(-7950000.0);
    });

    it('settles several invoices at different rates in one journal', function () {
        $first = openUsdInvoice('1000', '15000');
        $second = openUsdInvoice('500', '15200');
        docPosted(usdReceipt('1500', '15500', [['open_item_id' => $first, 'amount' => '1000'], ['open_item_id' => $second, 'amount' => '500']])->assertCreated()->json('data.id'));

        // 15,500,000 - 15,000,000 and 7,750,000 - 7,600,000.
        expect(DB::table('sl_allocations')->sum('realized_difference'))->toEqual('650000.000000')
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->gain))->toBe(-650000.0);
    });

    it('books nothing when the rates are the same, and nothing in the functional currency', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $posted = docPosted(usdReceipt('1000', '15000', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));

        expect(DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->count())->toBe(2)
            ->and(DB::table('sl_allocations')->value('realized_difference'))->toBe('0.000000');
    });

});

describe('without the realized exchange accounts', function () {
    it('is refused at submit while the gain or loss account is not a valid target', function () {
        $invoice = openUsdInvoice('1000', '15000');
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/".docAccountId('7130').'/deactivate')->assertOk();

        docAct($this->preparer, usdReceipt('1000', '15500', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'), 'submit')
            ->assertStatus(422)->assertJsonPath('code', 'MAPPING_REQUIRED');
    });
});

describe('an allocation asked for afterwards at other rates', function () {
    beforeEach(function () {
        mapFxRoles();
        $this->invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1000', '15500')->assertCreated()->json('data.id'));
        $this->advance = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');
    });

    it('books the difference in a journal of its own, and the opposite one when it is cancelled', function () {
        $body = ['open_item_id' => $this->advance, 'counterpart_open_item_id' => $this->invoice, 'amount' => '1000', 'allocation_date' => '2026-03-20'];
        $id = $this->actingAs($this->preparer)->postJson("{$this->url}/allocations", $body)->assertCreated()->assertJsonPath('data.realized_difference', '500000.000000')->json('data.id');

        $journalId = (string) DB::table('sl_allocations')->where('id', $id)->value('journal_id');
        $journal = DB::table('gl_journals')->where('id', $journalId)->first();

        expect($journal->source)->toBe('subledger')
            ->and($journal->transaction_type_key)->toBe('system.fx_realized')
            ->and(DB::table('gl_journal_lines')->where('journal_id', $journalId)->count())->toBe(2)
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->gain))->toBe(-500000.0);

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/{$id}/cancel", ['reason' => 'Salah faktur'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect(DB::table('sl_allocations')->where('id', $id)->value('cancel_journal_id'))->not->toBeNull()
            // Both open items are open again at what they carried, and the ledger agrees.
            ->and(functionalBalance($this->receivables))->toBe(-500000.0)
            ->and(functionalBalance($this->gain))->toBe(0.0)
            ->and(DB::table('sl_open_items')->where('id', $this->invoice)->value('remaining_functional'))->toBe('15000000.000000')
            ->and(DB::table('sl_open_items')->where('id', $this->advance)->value('remaining_functional'))->toBe('15500000.000000');
    });

    it('makes no journal when the items carry the same value', function () {
        $other = openUsdInvoice('500', '15500');
        $journals = DB::table('gl_journals')->count();

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations", ['open_item_id' => $this->advance, 'counterpart_open_item_id' => $other, 'amount' => '500', 'allocation_date' => '2026-03-20'])
            ->assertCreated()->assertJsonPath('data.realized_difference', '0.000000')->assertJsonPath('data.journal_id', null);

        expect(DB::table('gl_journals')->count())->toBe($journals);
    });
});
