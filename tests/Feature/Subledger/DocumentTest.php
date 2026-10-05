<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Documents and open items recorded by entries with a subledger route (SL-FR-04, TE-FR-05, TE-FR-10).

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

    // The template does not restrict the sources of control and cash accounts, so the company does it.
    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->supplier = docParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
});

function docAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function docParty(array $overrides = []): string
{
    return (string) test()->actingAs(test()->admin)->postJson(test()->url.'/parties', array_merge(['name' => 'PT Maju Jaya', 'is_customer' => true], $overrides))->assertCreated()->json('data.id');
}

function docMapPayables(): void
{
    test()->actingAs(test()->admin)->putJson(test()->url.'/posting-profiles/'.test()->profileId.'/accounts/payables_control', ['lock_version' => 0, 'account_id' => test()->payables])->assertOk();
}

function docFixed(string $accountId): array
{
    return ['source' => 'fixed', 'account_id' => $accountId];
}

function docControl(string $partyField = 'party'): array
{
    return ['source' => 'control_account', 'party_field' => $partyField];
}

/** An invoice to a customer: the receivable against revenue. */
function docInvoiceBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'fields' => [
            ['name' => 'party', 'type' => 'party', 'label' => 'Pelanggan'],
            ['name' => 'number', 'type' => 'reference', 'label' => 'Nomor', 'required' => false],
            ['name' => 'due', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docFixed(test()->sales), 'apply_dimensions' => false, 'reuses_field' => true],
        ],
        'subledger_route' => ['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'party', 'document_number_field' => 'number', 'due_date_field' => 'due', 'cash_account_field' => null],
    ], $overrides);
}

/** A receipt from a customer: cash against the receivable. */
function docPaymentBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'fields' => [
            ['name' => 'party', 'type' => 'party', 'label' => 'Pelanggan'],
            ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Rekening'],
            ['name' => 'number', 'type' => 'reference', 'label' => 'Nomor', 'required' => false],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
        ],
        'subledger_route' => ['kind' => 'payment', 'control_role' => 'receivables_control', 'party_field' => 'party', 'document_number_field' => 'number', 'due_date_field' => null, 'cash_account_field' => 'bank'],
    ], $overrides);
}

/** Creates a type of the key and publishes a version of the body; returns the response of the draft when it is refused. */
function docType(string $key, array $body)
{
    $typeId = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types', ['key' => $key, 'label' => ucfirst($key)])->assertCreated()->json('data.id');
    $draft = test()->actingAs(test()->admin)->postJson(test()->url."/transaction-types/{$typeId}/versions", $body);

    if ($draft->status() !== 201) {
        return $draft;
    }

    $number = (int) collect($draft->json('data.versions'))->max('version');
    test()->actingAs(test()->admin)->postJson(test()->url."/transaction-types/{$typeId}/versions/{$number}/publish")->assertOk();

    return $draft;
}

function docEntry(string $key, array $values, array $overrides = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_merge([
        'transaction_type_key' => $key,
        'accounting_date' => '2026-03-15',
        'description' => 'Dokumen subledger',
        'values' => $values,
    ], $overrides));
}

function docAct(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

/** Submits, approves and posts an entry; returns the posted entry. */
function docPosted(string $entryId): array
{
    docAct(test()->preparer, $entryId, 'submit')->assertOk();
    docAct(test()->approver, $entryId, 'approve')->assertOk();

    return docAct(test()->approver, $entryId, 'post')->assertOk()->assertJsonPath('data.status', 'posted')->json('data');
}

describe('an invoice (SL-FR-04)', function () {
    beforeEach(function () {
        docType('credit_sale', docInvoiceBody());
    });

    it('records the document and its open item and posts the journal from the source subledger', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'number' => 'INV-001', 'due' => '2026-04-14', 'total' => '1000000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $document = DB::table('sl_documents')->where('company_id', $this->companyId)->first();
        $item = DB::table('sl_open_items')->where('company_id', $this->companyId)->first();
        $journal = DB::table('gl_journals')->where('id', $posted['journal_id'])->first();

        expect($journal->source)->toBe('subledger')
            ->and($journal->entry_id)->toBe($id)
            ->and($document)->toMatchArray([
                'kind' => 'invoice', 'control_role' => 'receivables_control', 'party_id' => $this->customer, 'cash_account_id' => null,
                'journal_id' => $posted['journal_id'], 'entry_id' => $id, 'document_number' => 'INV-001', 'currency' => 'IDR',
                'amount' => '1000000.000000', 'functional_amount' => '1000000.000000',
            ])
            ->and(substr($document->due_date, 0, 10))->toBe('2026-04-14')
            ->and(substr($document->document_date, 0, 10))->toBe('2026-03-15')
            ->and($item)->toMatchArray([
                'document_id' => $document->id, 'party_id' => $this->customer, 'kind' => 'invoice', 'side' => 'debit', 'status' => 'open',
                'original_amount' => '1000000.000000', 'remaining_amount' => '1000000.000000', 'remaining_functional' => '1000000.000000',
            ])
            ->and(DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->orderBy('line_number')->pluck('account_id')->all())->toBe([$this->receivables, $this->sales])
            ->and(DB::table('sl_cash_transactions')->count())->toBe(0)
            ->and(AuditEntry::where('event', 'document.recorded')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('takes the number of the entry and the date of the document when the fields are empty', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '500000'], ['document_date' => '2026-03-10'])->assertCreated()->json('data.id');
        $posted = docPosted($id);
        $document = DB::table('sl_documents')->where('company_id', $this->companyId)->first();

        expect($document->document_number)->toBe($posted['number'] ?? $posted['journal_number'])
            ->and(substr($document->document_date, 0, 10))->toBe('2026-03-10')
            ->and(substr($document->due_date, 0, 10))->toBe('2026-03-10');
    });

    it('lists documents and open items for the permission that reads them', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'number' => 'INV-001', 'total' => '1000000'])->assertCreated()->json('data.id');
        docPosted($id);
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $documents = $this->actingAs($viewer)->getJson("{$this->url}/documents?filter[party_id]={$this->customer}&filter[kind]=invoice")->assertOk()->assertJsonCount(1, 'data')->json('data');
        $items = $this->actingAs($viewer)->getJson("{$this->url}/open-items?filter[status]=open&filter[control_role]=receivables_control")->assertOk()->assertJsonCount(1, 'data')->json('data');

        expect($documents[0])->toMatchArray(['kind' => 'invoice', 'document_number' => 'INV-001', 'amount' => '1000000.000000', 'entry_id' => $id])
            ->and($items[0])->toMatchArray(['document_id' => $documents[0]['id'], 'side' => 'debit', 'remaining_amount' => '1000000.000000', 'status' => 'open']);

        $this->actingAs($viewer)->getJson("{$this->url}/documents/{$documents[0]['id']}")->assertOk()->assertJsonPath('data.journal_id', $documents[0]['journal_id']);
        $this->actingAs($viewer)->getJson("{$this->url}/open-items/{$items[0]['id']}")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/open-items?filter[status]=settled")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson("{$this->url}/documents?filter[kind]=payment")->assertOk()->assertJsonCount(0, 'data');
    });

    it('does not show the documents of a company to the members of another', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000000'])->assertCreated()->json('data.id');
        docPosted($id);
        $documentId = (string) DB::table('sl_documents')->value('id');

        $outsider = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($outsider, 'Other');

        $this->actingAs($outsider)->getJson("/api/v1/companies/{$otherCompany}/documents/{$documentId}")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/v1/companies/{$otherCompany}/documents")->assertOk()->assertJsonCount(0, 'data');
    });

    it('returns the same document when the entry is posted again, and never records a second one', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000000'])->assertCreated()->json('data.id');
        $first = docPosted($id);
        $second = docAct($this->approver, $id, 'post')->assertOk()->json('data');

        expect($second['journal_id'])->toBe($first['journal_id'])
            ->and(DB::table('sl_documents')->count())->toBe(1)
            ->and(DB::table('sl_open_items')->count())->toBe(1)
            ->and(DB::table('gl_journals')->where('entry_id', $id)->count())->toBe(1);
    });

    it('refuses the party at submit with an error on its field', function () {
        $inactive = docParty(['name' => 'PT Tidur']);
        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$inactive}/deactivate")->assertOk();
        $supplierOnly = $this->supplier;

        $submit = fn (string $party) => docAct($this->preparer, docEntry('credit_sale', ['party' => $party, 'total' => '1000'])->assertCreated()->json('data.id'), 'submit');

        $submit($inactive)->assertUnprocessable()->assertJsonValidationErrors(['values.party']);
        $submit($supplierOnly)->assertUnprocessable()->assertJsonValidationErrors(['values.party']);

        expect(DB::table('sl_documents')->count())->toBe(0);
    });

    it('does not accept a party or a due date that does not exist or fits no document', function () {
        docEntry('credit_sale', ['party' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'total' => '1000'])->assertUnprocessable()->assertJsonValidationErrors(['values.party']);

        $before = docEntry('credit_sale', ['party' => $this->customer, 'due' => '2026-03-01', 'total' => '1000'])->assertCreated()->json('data.id');
        docAct($this->preparer, $before, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.due']);
    });

    it('is refused with MAPPING_REQUIRED while the control account is not mapped', function () {
        docType('credit_purchase', docInvoiceBody([
            'posting_rules' => [
                ['side' => 'credit', 'account' => docControl()],
                ['side' => 'debit', 'account' => docFixed($this->expense)],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control'],
        ]))->assertCreated();

        docEntry('credit_purchase', ['party' => $this->supplier, 'total' => '1000'])->assertStatus(422)->assertJsonPath('code', 'MAPPING_REQUIRED');

        docMapPayables();

        $id = docEntry('credit_purchase', ['party' => $this->supplier, 'total' => '750000', 'number' => 'BILL-9'])->assertCreated()->json('data.id');
        docPosted($id);

        $item = DB::table('sl_open_items')->where('party_id', $this->supplier)->first();

        expect($item)->toMatchArray(['kind' => 'invoice', 'control_role' => 'payables_control', 'side' => 'credit', 'document_number' => 'BILL-9', 'remaining_amount' => '750000.000000']);
    });

    it('refuses a customer on a payables route', function () {
        docMapPayables();
        docType('credit_purchase', docInvoiceBody([
            'posting_rules' => [['side' => 'credit', 'account' => docControl()], ['side' => 'debit', 'account' => docFixed($this->expense)]],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control'],
        ]))->assertCreated();

        $id = docEntry('credit_purchase', ['party' => $this->customer, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.party']);
    });

    it('records a foreign currency invoice at the rate of the entry in both currencies', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
        docType('usd_sale', docInvoiceBody(['allowed_currencies' => ['USD'], 'allow_rate_input' => true]))->assertCreated();

        $id = docEntry('usd_sale', ['party' => $this->customer, 'total' => '1000'], ['currency' => 'USD', 'exchange_rate' => '15000'])->assertCreated()->json('data.id');
        docPosted($id);

        $document = DB::table('sl_documents')->first();
        $item = DB::table('sl_open_items')->first();

        expect($document)->toMatchArray(['currency' => 'USD', 'amount' => '1000.000000', 'functional_amount' => '15000000.000000', 'exchange_rate' => '15000.000000000000'])
            ->and($item)->toMatchArray(['currency' => 'USD', 'original_amount' => '1000.000000', 'original_functional' => '15000000.000000', 'remaining_functional' => '15000000.000000']);
    });

    it('leaves nothing behind when the ledger refuses the journal', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000'], ['accounting_date' => '2025-12-15'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertStatus(422);

        expect(DB::table('sl_documents')->count())->toBe(0)->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('cannot be reversed through the ledger, which would leave its open item standing', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $this->actingAs($this->preparer)->postJson("{$this->url}/journals/{$posted['journal_id']}/reverse", ['reversal_date' => '2026-03-20', 'reason' => 'Salah'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000'], ['auto_reverse_on' => '2026-04-10'])->assertUnprocessable()->assertJsonValidationErrors(['auto_reverse_on']);
    });

    it('keeps documents unchanged and open items to their remaining amount', function () {
        $id = docEntry('credit_sale', ['party' => $this->customer, 'total' => '1000'])->assertCreated()->json('data.id');
        docPosted($id);

        // Each refusal runs in a savepoint, so the transaction of the test goes on after it.
        $refused = fn (callable $change) => fn () => DB::transaction($change);

        expect($refused(fn () => DB::table('sl_documents')->update(['amount' => '1'])))->toThrow(QueryException::class)
            ->and($refused(fn () => DB::table('sl_open_items')->update(['original_amount' => '2000'])))->toThrow(QueryException::class)
            ->and($refused(fn () => DB::table('sl_open_items')->update(['remaining_amount' => '5000'])))->toThrow(QueryException::class)
            ->and($refused(fn () => DB::table('sl_documents')->delete()))->toThrow(Exception::class);

        DB::table('sl_open_items')->update(['remaining_amount' => '0', 'remaining_functional' => '0', 'status' => 'settled']);

        expect(DB::table('sl_open_items')->value('status'))->toBe('settled');
    });
});

describe('a payment (SL-FR-04, SL-FR-11)', function () {
    beforeEach(function () {
        docType('customer_receipt', docPaymentBody());
    });

    it('records the document, the cash it moved and an open item on the credit side', function () {
        $id = docEntry('customer_receipt', ['party' => $this->customer, 'bank' => $this->cashAccount, 'number' => 'RCPT-1', 'total' => '600000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $document = DB::table('sl_documents')->first();
        $item = DB::table('sl_open_items')->first();
        $cash = DB::table('sl_cash_transactions')->first();

        expect($document)->toMatchArray(['kind' => 'payment', 'cash_account_id' => $this->cashAccount, 'document_number' => 'RCPT-1', 'amount' => '600000.000000'])
            ->and($item)->toMatchArray(['kind' => 'payment', 'side' => 'credit', 'remaining_amount' => '600000.000000'])
            ->and($cash)->toMatchArray([
                'cash_account_id' => $this->cashAccount, 'direction' => 'in', 'amount' => '600000.000000', 'currency' => 'IDR',
                'reference' => 'RCPT-1', 'document_id' => $document->id, 'cleared_on' => null,
            ])
            ->and(DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->orderBy('line_number')->pluck('account_id')->all())->toBe([$this->bank, $this->receivables]);

        $this->actingAs($this->admin)->getJson("{$this->url}/cash-transactions?filter[cash_account_id]={$this->cashAccount}")
            ->assertOk()->assertJsonPath('data.0.document_id', $document->id);
    });

    it('refuses an unknown or inactive cash account on its field', function () {
        docEntry('customer_receipt', ['party' => $this->customer, 'bank' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'total' => '1000'])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.bank']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$this->cashAccount}/deactivate")->assertOk();
        $id = docEntry('customer_receipt', ['party' => $this->customer, 'bank' => $this->cashAccount, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.bank']);
        expect(DB::table('sl_cash_transactions')->count())->toBe(0);
    });

    it('refuses a cash account whose currency is not the one of the lines', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
        $usdBank = docAccountId('1115');
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$usdBank}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$usdBank}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
        $usd = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
            'name' => 'BCA USD', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'USD', 'account_id' => $usdBank,
        ])->assertCreated()->json('data.id');

        $id = docEntry('customer_receipt', ['party' => $this->customer, 'bank' => $usd, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    });

    it('refuses a line to the account of another cash account, which would move cash nobody recorded', function () {
        $petty = docAccountId('1111');
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$petty}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$petty}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", ['name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $petty])->assertCreated();

        docType('receipt_with_petty', docPaymentBody([
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => docFixed($petty), 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
            ],
        ]))->assertCreated();

        // Both debits take the whole total: the journal does not balance, but the stray line is named first.
        $id = docEntry('receipt_with_petty', ['party' => $this->customer, 'bank' => $this->cashAccount, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        expect(DB::table('sl_documents')->count())->toBe(0);
    });
});

describe('what the ledger accepts on control and cash accounts (GL-FR-02)', function () {
    it('keeps a plain entry from posting to the control account of a party', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'manual', 'label' => 'Manual'])->assertCreated();

        $entry = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'manual', 'accounting_date' => '2026-03-15', 'description' => 'Langsung',
            'lines' => [['account_id' => $this->receivables, 'debit' => '1000'], ['account_id' => $this->sales, 'credit' => '1000']],
        ])->assertCreated()->json('data.id');

        docAct($this->preparer, $entry, 'submit')->assertStatus(422)->assertJsonPath('code', 'POSTING_SOURCE_NOT_ALLOWED');
    });
});

describe('the subledger route of a version (TE-FR-02, TE-FR-10)', function () {
    beforeEach(function () {
        $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'routed', 'label' => 'Routed'])->assertCreated()->json('data.id');
        $this->draft = fn (array $body) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", $body);
    });

    it('stores the route with the version and returns it', function () {
        $versions = ($this->draft)(docInvoiceBody())->assertCreated()->json('data.versions');

        expect(collect($versions)->last()['subledger_route'])->toEqualCanonicalizing([
            'kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'party',
            'document_number_field' => 'number', 'due_date_field' => 'due', 'cash_account_field' => null,
        ])->and($versions[0]['subledger_route'])->toBeNull();
    });

    it('refuses a control or cash rule without a route, and a route on a free-lines version', function () {
        ($this->draft)(docInvoiceBody(['subledger_route' => null]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account']);
        ($this->draft)(['mode' => 'free_lines', 'fields' => [], 'subledger_route' => docInvoiceBody()['subledger_route']])->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
    });

    it('refuses a route that does not fit the fields or the rules', function () {
        $route = fn (array $changes) => docInvoiceBody(['subledger_route' => $changes]);

        ($this->draft)($route(['kind' => 'refund']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.kind']);
        ($this->draft)($route(['control_role' => 'cash']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.control_role']);
        ($this->draft)($route(['party_field' => 'total']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.party_field']);
        ($this->draft)($route(['due_date_field' => 'number']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.due_date_field']);
        ($this->draft)($route(['document_number_field' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.document_number_field']);
        ($this->draft)($route(['cash_account_field' => 'party']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.cash_account_field']);
        ($this->draft)($route(['kind' => 'payment']))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.cash_account_field']);
    });

    it('refuses rules on the wrong side or without the control or cash account the route needs', function () {
        // An invoice to a customer debits the receivable.
        ($this->draft)(docInvoiceBody(['posting_rules' => [['side' => 'credit'], ['side' => 'debit', 'account' => docFixed($this->sales)]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.side']);
        // A route needs a rule on the control account.
        ($this->draft)(docInvoiceBody(['posting_rules' => [['account' => docFixed($this->receivables)]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
        // An invoice moves no cash.
        ($this->draft)(docInvoiceBody(['fields' => [4 => ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Rekening']], 'posting_rules' => [1 => ['account' => ['source' => 'cash_account', 'field' => 'bank']]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.1.account']);
        // A payment needs a rule on the cash account, on the other side of the control account.
        ($this->draft)(docPaymentBody(['posting_rules' => [['account' => docFixed($this->bank)]]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
        ($this->draft)(docPaymentBody(['posting_rules' => [['side' => 'credit']]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.side']);
        // The rules name the fields of the route and no others.
        ($this->draft)(docInvoiceBody(['fields' => [4 => ['name' => 'other', 'type' => 'party', 'label' => 'Lain']], 'posting_rules' => [0 => ['account' => docControl('other')]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account.party_field']);
    });

    it('counts the party field of a control rule as a field several rules share', function () {
        $body = docInvoiceBody(['fields' => [4 => ['name' => 'fee', 'type' => 'amount', 'label' => 'Biaya']]]);
        $body['posting_rules'][] = ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'fee', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => false];
        $number = (int) collect(($this->draft)($body)->assertCreated()->json('data.versions'))->max('version');

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.2.reuses_field']);

        $body['posting_rules'][2]['reuses_field'] = true;
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", $body)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")->assertOk();
    });

    it('keeps the route of a published version', function () {
        $versions = ($this->draft)(docInvoiceBody())->assertCreated()->json('data.versions');
        $number = (int) collect($versions)->max('version');
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")->assertOk();

        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", docInvoiceBody(['subledger_route' => ['due_date_field' => null]]))->assertConflict();
    });
});

describe('the credit sale template (TE-FR-01)', function () {
    it('is imported with its route and posts an invoice', function () {
        $template = $this->actingAs($this->admin)->getJson("{$this->url}/transaction-type-templates/general")->assertOk()->json('data.types');
        $sale = collect($template)->firstWhere('key', 'credit_sale');

        expect($sale['subledger_route'])->toMatchArray(['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'customer'])
            ->and(collect($template)->firstWhere('key', 'general_journal')['subledger_route'])->toBeNull();

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/import-template", ['template' => 'general', 'confirmed' => true, 'keys' => ['credit_sale']])->assertCreated();

        $id = docEntry('credit_sale', ['customer' => $this->customer, 'invoice_number' => 'FK-1', 'total' => '2500000', 'revenue_account' => $this->sales])->assertCreated()->json('data.id');
        docPosted($id);

        expect(DB::table('sl_open_items')->first())->toMatchArray(['document_number' => 'FK-1', 'remaining_amount' => '2500000.000000', 'side' => 'debit']);
    });
});
