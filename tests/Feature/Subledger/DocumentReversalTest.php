<?php

use App\Models\User;
use App\Modules\TransactionEntry\Application\EntryReversals;
use App\Platform\Audit\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Reversal of documents routed from entries (SL-FR-12, SL-AC-07, TE-AC-07). The helpers to build types and
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
    $this->bank = docAccountId('1112');
    $this->usdBankAccount = docAccountId('1115');
    $this->sales = docAccountId('4110');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank, $this->usdBankAccount] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

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

describe('an invoice (SL-AC-07, TE-AC-07)', function () {
    it('removes its open item and posts the mirror image of its journal', function () {
        $invoice = docOpenInvoice('1000000');
        $journal = journalOfDocument($invoice);

        $done = reversePosted($journal);

        $lines = DB::table('gl_journal_lines')->where('journal_id', $done['journal_id'])->orderBy('line_number')->get();

        expect(docItem($invoice))->toMatchArray(['status' => 'reversed', 'remaining_amount' => '0.000000', 'remaining_functional' => '0.000000', 'original_amount' => '1000000.000000'])
            ->and($lines->pluck('account_id')->all())->toBe([$this->receivables, $this->sales])
            ->and((float) $lines[0]->credit)->toBe(1000000.0)
            ->and((float) $lines[1]->debit)->toBe(1000000.0)
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(DB::table('gl_journals')->where('id', $done['journal_id'])->value('reverses_journal_id'))->toBe($journal)
            ->and(DB::table('sl_documents')->count())->toBe(1)
            ->and(DB::table('sl_document_reversals')->first())->toMatchArray(['journal_id' => $done['journal_id']])
            ->and(AuditEntry::where('event', 'document.reversed')->where('company_id', $this->companyId)->count())->toBe(1);

        $document = $this->actingAs($this->admin)->getJson("{$this->url}/documents/".DB::table('sl_documents')->value('id'))->assertOk()
            ->assertJsonPath('data.reversal_journal_id', $done['journal_id'])->assertJsonPath('data.reversal_date', '2026-03-20')->json('data');

        $this->actingAs($this->admin)->getJson("{$this->url}/open-items?filter[status]=reversed")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_id', $document['id']);
        $this->actingAs($this->admin)->getJson("{$this->url}/open-items?filter[status]=open")->assertOk()->assertJsonCount(0, 'data');
    });

    it('is reversed once, and what it left cannot be settled any more', function () {
        $invoice = docOpenInvoice('1000000');
        reversePosted(journalOfDocument($invoice));

        reverseRequest(journalOfDocument($invoice))->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $advance = docPosted(docReceipt('300000')->assertCreated()->json('data.id'));
        $advanceItem = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');
        $body = ['open_item_id' => $advanceItem, 'counterpart_open_item_id' => $invoice, 'amount' => '300000', 'allocation_date' => '2026-03-20'];

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations", $body)->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        expect($advance['status'])->toBe('posted');
    });

    it('is refused while an allocation against it is active, and goes through once the allocation is cancelled', function () {
        $invoice = docOpenInvoice('1000000');
        docPosted(docReceipt('600000', [['open_item_id' => $invoice, 'amount' => '600000']])->assertCreated()->json('data.id'));
        $allocation = (string) DB::table('sl_allocations')->value('id');
        $journal = journalOfDocument($invoice);

        reverseRequest($journal)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/{$allocation}/cancel", ['reason' => 'Salah faktur'])->assertOk();

        reversePosted($journal);

        expect(docItem($invoice)->status)->toBe('reversed')
            ->and(functionalBalance($this->receivables))->toBe(-600000.0)
            ->and(DB::table('sl_open_items')->where('kind', 'payment')->first())->toMatchArray(['status' => 'open', 'remaining_amount' => '600000.000000']);
    });

    it('is refused again at submit when an allocation was made after the request', function () {
        $invoice = docOpenInvoice('1000000');
        $reversal = reverseRequest(journalOfDocument($invoice))->assertCreated()->json('data.id');

        docPosted(docReceipt('400000', [['open_item_id' => $invoice, 'amount' => '400000']])->assertCreated()->json('data.id'));

        docAct($this->preparer, $reversal, 'submit')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        expect(docItem($invoice)->status)->toBe('open');
    });

    it('is refused when the period of the reversal does not accept the posting', function () {
        $invoice = docOpenInvoice('1000000');
        $april = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$april}/close", [])->assertOk();

        reverseRequest(journalOfDocument($invoice), ['reversal_date' => '2026-04-10'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_OPEN');

        expect(docItem($invoice)->status)->toBe('open');
    });
});

describe('a payment (SL-FR-12)', function () {
    it('returns its cash with a transaction of the opposite direction', function () {
        docPosted(docReceipt('250000')->assertCreated()->json('data.id'));
        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');

        $done = reversePosted(journalOfDocument($payment));

        $transactions = DB::table('sl_cash_transactions')->orderBy('created_at')->orderBy('id')->get();

        expect($transactions)->toHaveCount(2)
            ->and($transactions->pluck('direction')->sort()->values()->all())->toBe(['in', 'out'])
            ->and($transactions->pluck('amount')->unique()->all())->toBe(['250000.000000'])
            ->and($transactions->firstWhere('direction', 'out'))->toMatchArray(['journal_id' => $done['journal_id'], 'cleared_on' => null])
            ->and(substr($transactions->firstWhere('direction', 'out')->transaction_date, 0, 10))->toBe('2026-03-20')
            ->and(functionalBalance($this->bank))->toBe(0.0)
            ->and(docItem($payment)->status)->toBe('reversed');
    });

    it('is refused while it has settled an invoice, and the invoice reopens when the allocation is cancelled', function () {
        $invoice = docOpenInvoice('1000000');
        docPosted(docReceipt('1000000', [['open_item_id' => $invoice, 'amount' => '1000000']])->assertCreated()->json('data.id'));
        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');

        reverseRequest(journalOfDocument($payment))->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/".DB::table('sl_allocations')->value('id').'/cancel', ['reason' => 'Salah faktur'])->assertOk();
        reversePosted(journalOfDocument($payment));

        expect(docItem($invoice))->toMatchArray(['status' => 'open', 'remaining_amount' => '1000000.000000'])
            ->and(functionalBalance($this->receivables))->toBe(1000000.0)
            ->and(functionalBalance($this->bank))->toBe(0.0);
    });
});

describe('a document that settled in a foreign currency (SL-FR-06, SL-FR-12)', function () {
    beforeEach(fn () => mapFxRoles());

    it('leaves the ledger at the open items when the payment is reversed after its allocation was cancelled', function () {
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1000', '15500', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));
        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/".DB::table('sl_allocations')->value('id').'/cancel', ['reason' => 'Salah faktur'])->assertOk();

        // The gain was booked with the payment and taken back by the cancellation.
        expect(functionalBalance($this->gain))->toBe(0.0);

        reversePosted(journalOfDocument($payment));

        // Only the invoice stands: the reversal took the gain out once more, so it is booked back.
        expect(functionalBalance($this->receivables))->toBe(15000000.0)
            ->and(functionalBalance($this->gain))->toBe(0.0)
            ->and(functionalBalance($this->usdBankAccount))->toBe(0.0)
            ->and(docItem($invoice))->toMatchArray(['status' => 'open', 'remaining_functional' => '15000000.000000'])
            ->and(DB::table('sl_open_items')->where('id', $payment)->value('status'))->toBe('reversed');
    });

    it('books no difference again for an allocation that had none', function () {
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1000', '15000', [['open_item_id' => $invoice, 'amount' => '1000']])->assertCreated()->json('data.id'));
        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');
        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/".DB::table('sl_allocations')->value('id').'/cancel', ['reason' => 'Salah faktur'])->assertOk();
        $journals = DB::table('gl_journals')->count();

        reversePosted(journalOfDocument($payment));

        // The reversal entry and its journal only.
        expect(DB::table('gl_journals')->count())->toBe($journals + 1);
    });
});

describe('journals the subledger posted for no document', function () {
    it('cannot be reversed from entries', function () {
        mapFxRoles();
        $invoice = openUsdInvoice('1000', '15000');
        docPosted(usdReceipt('1000', '15500')->assertCreated()->json('data.id'));
        $advance = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations", ['open_item_id' => $advance, 'counterpart_open_item_id' => $invoice, 'amount' => '1000', 'allocation_date' => '2026-03-20'])->assertCreated();

        reverseRequest((string) DB::table('sl_allocations')->value('journal_id'))->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });
});

describe('scheduled reversal of a routed entry (TE-FR-11, TE-AC-09)', function () {
    it('reverses the document on the date the entry asked for', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000000'], ['auto_reverse_on' => '2026-04-10'])->assertCreated()->json('data.id');
        docPosted($id);
        $invoice = (string) DB::table('sl_open_items')->where('kind', 'invoice')->value('id');

        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-10 12:00:00')))->toBe(['done' => 1, 'failed' => 0])
            ->and(docItem($invoice)->status)->toBe('reversed')
            ->and(functionalBalance($this->receivables))->toBe(0.0);
    });

    it('fails visibly while the invoice still has an allocation, and leaves it standing', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000000'], ['auto_reverse_on' => '2026-04-10'])->assertCreated()->json('data.id');
        docPosted($id);
        $invoice = (string) DB::table('sl_open_items')->where('kind', 'invoice')->value('id');
        docPosted(docReceipt('300000', [['open_item_id' => $invoice, 'amount' => '300000']])->assertCreated()->json('data.id'));

        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-10 12:00:00')))->toBe(['done' => 0, 'failed' => 1]);

        $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.auto_reversal_status', 'failed');
        expect(docItem($invoice)->status)->toBe('open')->and(DB::table('sl_document_reversals')->count())->toBe(0);
    });
});
