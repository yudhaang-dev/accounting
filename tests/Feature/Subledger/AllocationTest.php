<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Allocations (SL-FR-05, SL-AC-03). The helpers to build types and entries are those of DocumentTest.

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
    $this->expense = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    docType('credit_sale', docInvoiceBody());
    docType('receipt', docSettlingBody());
});

/** A receipt that names the open items it settles. */
function docSettlingBody(array $overrides = []): array
{
    return docPaymentBody(array_replace_recursive([
        'fields' => [4 => ['name' => 'allocs', 'type' => 'allocations', 'label' => 'Alokasi', 'required' => false]],
        'subledger_route' => ['allocations_field' => 'allocs'],
    ], $overrides));
}

/** Posts an invoice of the customer and returns the ID of its open item. */
function docOpenInvoice(string $total = '1000000', ?string $party = null): string
{
    $id = docEntry('credit_sale', ['party' => $party ?? test()->customer, 'total' => $total])->assertCreated()->json('data.id');
    docPosted($id);

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

function docReceipt(string $total, array $allocations = [], ?string $party = null)
{
    return docEntry('receipt', array_filter(['party' => $party ?? test()->customer, 'bank' => test()->cashAccount, 'total' => $total, 'allocs' => $allocations ?: null]));
}

function docItem(string $id): object
{
    return DB::table('sl_open_items')->where('id', $id)->first();
}

describe('allocations made by a document (SL-FR-05)', function () {
    it('settles part of an invoice with a payment and leaves the rest open', function () {
        $invoice = docOpenInvoice();
        $posted = docPosted(docReceipt('600000', [['open_item_id' => $invoice, 'amount' => '600000']])->assertCreated()->json('data.id'));

        $payment = DB::table('sl_open_items')->where('kind', 'payment')->first();
        $allocation = DB::table('sl_allocations')->first();

        expect(docItem($invoice))->toMatchArray(['remaining_amount' => '400000.000000', 'remaining_functional' => '400000.000000', 'status' => 'open'])
            ->and($payment)->toMatchArray(['remaining_amount' => '0.000000', 'status' => 'settled'])
            ->and($allocation)->toMatchArray([
                'debit_item_id' => $invoice, 'credit_item_id' => $payment->id, 'amount' => '600000.000000', 'functional_amount' => '600000.000000',
                'status' => 'active', 'document_id' => $payment->document_id,
            ])
            ->and(substr($allocation->allocation_date, 0, 10))->toBe('2026-03-15')
            ->and(DB::table('gl_journals')->where('id', $posted['journal_id'])->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("{$this->url}/allocations?filter[open_item_id]={$invoice}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '600000.000000');
    });

    it('keeps what a payment does not settle as an advance on the party (SL-AC-03)', function () {
        $invoice = docOpenInvoice();
        docPosted(docReceipt('1200000', [['open_item_id' => $invoice, 'amount' => '1000000']])->assertCreated()->json('data.id'));

        $payment = DB::table('sl_open_items')->where('kind', 'payment')->first();

        expect(docItem($invoice))->toMatchArray(['remaining_amount' => '0.000000', 'status' => 'settled'])
            ->and($payment)->toMatchArray(['side' => 'credit', 'remaining_amount' => '200000.000000', 'status' => 'open']);

        // Without any allocation the whole payment waits for one.
        docPosted(docReceipt('300000')->assertCreated()->json('data.id'));
        expect(DB::table('sl_open_items')->where('kind', 'payment')->orderByDesc('id')->value('remaining_amount'))->toBe('300000.000000');
    });

    it('settles several invoices with one payment, and one invoice with several', function () {
        $first = docOpenInvoice('1000000');
        $second = docOpenInvoice('500000');
        docPosted(docReceipt('1300000', [['open_item_id' => $first, 'amount' => '1000000'], ['open_item_id' => $second, 'amount' => '300000']])->assertCreated()->json('data.id'));
        docPosted(docReceipt('200000', [['open_item_id' => $second, 'amount' => '200000']])->assertCreated()->json('data.id'));

        expect(docItem($first)->status)->toBe('settled')
            ->and(docItem($second))->toMatchArray(['remaining_amount' => '0.000000', 'status' => 'settled'])
            ->and(DB::table('sl_allocations')->count())->toBe(3);
    });

    it('refuses what the document may not settle, on the field that names it, and records nothing', function () {
        $invoice = docOpenInvoice('1000000');
        $other = docOpenInvoice('500000', docParty(['name' => 'PT Lain']));
        $submit = fn (array $allocations, string $total = '2000000') => docAct($this->preparer, docReceipt($total, $allocations)->assertCreated()->json('data.id'), 'submit');

        $submit([['open_item_id' => $other, 'amount' => '100']])->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit([['open_item_id' => $invoice, 'amount' => '1000001']])->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit([['open_item_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'amount' => '1']])->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit([['open_item_id' => $invoice, 'amount' => '1'], ['open_item_id' => $invoice, 'amount' => '1']])->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit([['open_item_id' => $invoice, 'amount' => '600000']], '500000')->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit([['open_item_id' => $invoice, 'amount' => '0']])->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);

        expect(DB::table('sl_allocations')->count())->toBe(0)->and(docItem($invoice)->remaining_amount)->toBe('1000000.000000');
    });

    it('refuses an item on the same side and an item that is settled already', function () {
        $invoice = docOpenInvoice();
        docPosted(docReceipt('1000000', [['open_item_id' => $invoice, 'amount' => '1000000']])->assertCreated()->json('data.id'));
        docPosted(docReceipt('50000')->assertCreated()->json('data.id'));
        $waiting = (string) DB::table('sl_open_items')->where('kind', 'payment')->orderByDesc('id')->value('id');

        $submit = fn (string $item) => docAct($this->preparer, docReceipt('100', [['open_item_id' => $item, 'amount' => '100']])->assertCreated()->json('data.id'), 'submit');

        // The invoice is settled; another payment is on the same side as this one.
        $submit($invoice)->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
        $submit($waiting)->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);
    });

    it('does not take allocations on a kind that settles nothing', function () {
        $draft = fn (array $body) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/".$this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'k'.uniqid(), 'label' => 'K'])->assertCreated()->json('data.id').'/versions', $body);

        $draft(docInvoiceBody([
            'fields' => [4 => ['name' => 'allocs', 'type' => 'allocations', 'label' => 'A', 'required' => false]],
            'subledger_route' => ['allocations_field' => 'allocs'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.allocations_field']);
    });
});

describe('allocations asked for afterwards (SL-FR-05)', function () {
    beforeEach(function () {
        $this->invoice = docOpenInvoice();
        docPosted(docReceipt('700000')->assertCreated()->json('data.id'));
        $this->advance = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');
        $this->allocate = fn (array $body = []) => $this->actingAs($this->preparer)->postJson("{$this->url}/allocations", array_merge([
            'open_item_id' => $this->advance, 'counterpart_open_item_id' => $this->invoice, 'amount' => '500000', 'allocation_date' => '2026-03-20',
        ], $body));
    });

    it('applies an advance to an invoice, whichever item is named first, without a journal', function () {
        $journals = DB::table('gl_journals')->count();

        ($this->allocate)()->assertCreated()->assertJsonPath('data.debit_item_id', $this->invoice)->assertJsonPath('data.credit_item_id', $this->advance)->assertJsonPath('data.document_id', null);
        ($this->allocate)(['open_item_id' => $this->invoice, 'counterpart_open_item_id' => $this->advance, 'amount' => '200000'])->assertCreated();

        expect(docItem($this->invoice)->remaining_amount)->toBe('300000.000000')
            ->and(docItem($this->advance))->toMatchArray(['remaining_amount' => '0.000000', 'status' => 'settled'])
            ->and(DB::table('gl_journals')->count())->toBe($journals)
            ->and(AuditEntry::where('event', 'allocation.created')->count())->toBe(2);
    });

    it('refuses what the open items do not allow', function () {
        ($this->allocate)(['amount' => '700001'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        ($this->allocate)(['counterpart_open_item_id' => $this->advance])->assertUnprocessable()->assertJsonValidationErrors(['counterpart_open_item_id']);
        ($this->allocate)(['allocation_date' => '2026-03-01'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        ($this->allocate)(['counterpart_open_item_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertUnprocessable()->assertJsonValidationErrors(['counterpart_open_item_id']);
        ($this->allocate)(['amount' => '12.5.5'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    });

    it('cancels an allocation with a reason, gives the amounts back and keeps the record', function () {
        $id = ($this->allocate)()->assertCreated()->json('data.id');

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/{$id}/cancel", ['reason' => 'Salah faktur'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Salah faktur');

        expect(docItem($this->invoice))->toMatchArray(['remaining_amount' => '1000000.000000', 'remaining_functional' => '1000000.000000', 'status' => 'open'])
            ->and(docItem($this->advance)->remaining_amount)->toBe('700000.000000')
            ->and(DB::table('sl_allocations')->count())->toBe(1)
            ->and(AuditEntry::where('event', 'allocation.cancelled')->count())->toBe(1);

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/{$id}/cancel", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        ($this->allocate)(['amount' => '700000'])->assertCreated();
    });

    it('lets the database change an allocation only by cancelling it, once', function () {
        ($this->allocate)()->assertCreated();
        $refused = fn (callable $change) => fn () => DB::transaction($change);

        expect($refused(fn () => DB::table('sl_allocations')->update(['amount' => '1'])))->toThrow(QueryException::class)
            ->and($refused(fn () => DB::table('sl_allocations')->delete()))->toThrow(Exception::class);
    });

    it('is managed with allocations.manage and read with allocations.read', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/allocations")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/allocations", ['open_item_id' => $this->advance, 'counterpart_open_item_id' => $this->invoice, 'amount' => '1', 'allocation_date' => '2026-03-20'])->assertForbidden();
    });
});

describe('write-offs (SL-FR-04, SL-FR-05)', function () {
    beforeEach(function () {
        $writeOff = docSettlingBody(['subledger_route' => ['kind' => 'write_off', 'cash_account_field' => null]]);
        $writeOff['fields'] = array_values(array_filter($writeOff['fields'], fn (array $field) => $field['name'] !== 'bank'));
        $writeOff['posting_rules'] = [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => docFixed($this->expense), 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
        ];
        $this->writeOff = $writeOff;
    });

    it('settles in full the amount of the write-off', function () {
        docType('write_off', $this->writeOff)->assertCreated();
        $invoice = docOpenInvoice();

        docAct($this->preparer, docEntry('write_off', ['party' => $this->customer, 'total' => '400000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '300000']]])->assertCreated()->json('data.id'), 'submit')
            ->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);

        docPosted(docEntry('write_off', ['party' => $this->customer, 'total' => '1000000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '1000000']]])->assertCreated()->json('data.id'));

        expect(docItem($invoice)->status)->toBe('settled')
            ->and(DB::table('sl_documents')->where('kind', 'write_off')->count())->toBe(1)
            ->and(DB::table('sl_open_items')->where('kind', 'write_off')->value('status'))->toBe('settled');
    });

    it('needs the allocations field on a write-off route', function () {
        $this->writeOff['subledger_route']['allocations_field'] = null;
        $this->writeOff['fields'] = array_values(array_filter($this->writeOff['fields'], fn (array $field) => $field['name'] !== 'allocs'));

        docType('write_off', $this->writeOff)->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.allocations_field']);
    });
});
