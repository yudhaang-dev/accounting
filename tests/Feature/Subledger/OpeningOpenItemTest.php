<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// Open items of the conversion date, imported with the opening balance batch and held to its control account
// rows (SL-FR-10, SL-AC-01, GL-AC-07). The helpers to build types and entries are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->receivables = docAccountId('1121');
    $this->payables = docAccountId('2110');

    foreach ([$this->receivables, $this->payables] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya', 'external_refs' => [['source_system' => 'legacy', 'external_ref' => 'C-001']]]);
    $this->supplier = docParty(['name' => 'UD Pemasok', 'roles' => ['supplier'], 'external_refs' => [['source_system' => 'legacy', 'external_ref' => 'S-001']]]);
});

/** A source balance sheet with receivables and payables, and the capital that makes it balance. */
function openingItemsPayload(array $overrides = []): array
{
    return array_merge([
        'source_key' => 'legacy-2026-04',
        'source_system' => 'legacy',
        'cutover_date' => '2026-04-01',
        'account_mapping' => [
            ['source_code' => 'A-120', 'account_code' => '1121'],
            ['source_code' => 'L-210', 'account_code' => '2110'],
            ['source_code' => 'E-300', 'account_code' => '3110'],
        ],
        'lines' => [
            ['source_account_code' => 'A-120', 'debit' => '1500000.00', 'credit' => '0'],
            ['source_account_code' => 'L-210', 'debit' => '0', 'credit' => '400000.00'],
            ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '1100000.00'],
        ],
        'open_items' => [
            ['party_ref' => 'C-001', 'control_role' => 'receivables_control', 'kind' => 'invoice', 'document_number' => 'INV-001', 'document_date' => '2026-02-10', 'due_date' => '2026-03-12', 'amount' => '1000000'],
            ['party_ref' => 'C-001', 'control_role' => 'receivables_control', 'kind' => 'invoice', 'document_number' => 'INV-002', 'document_date' => '2026-03-20', 'amount' => '700000'],
            ['party_ref' => 'C-001', 'control_role' => 'receivables_control', 'kind' => 'credit_note', 'document_number' => 'CN-001', 'document_date' => '2026-03-25', 'amount' => '200000'],
            ['party_ref' => 'S-001', 'control_role' => 'payables_control', 'kind' => 'invoice', 'document_number' => 'BILL-001', 'document_date' => '2026-03-05', 'amount' => '400000'],
        ],
    ], $overrides);
}

function importOpeningItems(array $overrides = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/opening-balance-imports', openingItemsPayload($overrides));
}

describe('importing open items with the opening balance (SL-AC-01, GL-AC-07)', function () {
    it('records the open items and the opening journal when their totals equal the control account rows', function () {
        $batch = importOpeningItems()->assertCreated()->json('data');

        $items = DB::table('sl_open_items')->orderBy('document_number')->get();
        $documents = DB::table('sl_documents')->get();

        expect($items->pluck('document_number')->all())->toBe(['BILL-001', 'CN-001', 'INV-001', 'INV-002'])
            ->and($items->pluck('side', 'document_number')->all())->toBe(['BILL-001' => 'credit', 'CN-001' => 'credit', 'INV-001' => 'debit', 'INV-002' => 'debit'])
            ->and($items->pluck('kind', 'document_number')->all())->toBe(['BILL-001' => 'invoice', 'CN-001' => 'credit_note', 'INV-001' => 'invoice', 'INV-002' => 'invoice'])
            ->and($items->every(fn ($item) => $item->status === 'open' && $item->remaining_amount === $item->original_amount))->toBeTrue()
            ->and(substr($items->firstWhere('document_number', 'INV-002')->due_date, 0, 10))->toBe('2026-03-20')
            ->and($documents->every(fn ($document) => (bool) $document->is_opening && $document->journal_id === $batch['journal_id'] && $document->entry_id === null))->toBeTrue()
            ->and($documents->firstWhere('document_number', 'INV-001')->control_account_id)->toBe($this->receivables)
            ->and($documents->firstWhere('document_number', 'BILL-001')->control_account_id)->toBe($this->payables)
            ->and($documents->firstWhere('document_number', 'INV-001')->party_id)->toBe($this->customer)
            ->and(AuditEntry::where('event', 'open_items.opening_imported')->where('company_id', $this->companyId)->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("{$this->url}/open-items?filter[control_role]=receivables_control")->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/documents?filter[kind]=credit_note")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_opening', true);
    });

    it('refuses the whole batch for a difference of the smallest amount, and records nothing', function () {
        $payload = openingItemsPayload();
        $payload['open_items'][0]['amount'] = '1000000.01';

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable();

        expect(DB::table('sl_open_items')->count())->toBe(0)->and(DB::table('sl_documents')->count())->toBe(0)->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('refuses a control account row that the open items do not cover', function () {
        $payload = openingItemsPayload();
        array_pop($payload['open_items']);

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable();

        expect(DB::table('sl_open_items')->count())->toBe(0)->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('does not double the open items or the balances when the batch is sent again (SL-AC-01)', function () {
        importOpeningItems()->assertCreated();
        importOpeningItems()->assertOk();

        expect(DB::table('sl_open_items')->count())->toBe(4)->and(DB::table('sl_documents')->count())->toBe(4)->and(DB::table('gl_journals')->count())->toBe(1);
    });

    it('refuses the same source key with other open items', function () {
        importOpeningItems()->assertCreated();

        $payload = openingItemsPayload();
        $payload['open_items'][0]['amount'] = '900000';
        $payload['open_items'][1]['amount'] = '800000';

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        expect(DB::table('sl_open_items')->count())->toBe(4);
    });

    it('still refuses a line on a control account when no open items come with it', function () {
        $payload = openingItemsPayload();
        unset($payload['open_items']);

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable();
    });
});

describe('what an opening open item must be', function () {
    it('names a party of the company, whatever role it holds', function () {
        $payload = openingItemsPayload();
        $payload['open_items'][0]['party_ref'] = 'C-404';

        $response = $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toContain('open_items.0.party')->not->toContain('open_items.3.party');
    });

    it('takes the party by ID as well', function () {
        $payload = openingItemsPayload();
        unset($payload['open_items'][0]['party_ref']);
        $payload['open_items'][0]['party_id'] = $this->customer;

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertCreated();
        expect(DB::table('sl_open_items')->where('document_number', 'INV-001')->value('party_id'))->toBe($this->customer);
    });

    it('is dated no later than the ledger start date and not a duplicate of another', function () {
        $payload = openingItemsPayload();
        $payload['open_items'][0]['document_date'] = '2026-04-02';
        $payload['open_items'][1]['document_number'] = 'INV-001';

        $response = $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toContain('open_items.0.document_date', 'open_items.1.document_number');
    });

    it('needs the rate of a foreign currency, and carries the functional value the ledger converts it to', function () {
        $payload = openingItemsPayload([
            'lines' => [
                ['source_account_code' => 'A-120', 'debit' => '100.00', 'credit' => '0', 'currency' => 'USD', 'exchange_rate' => '15000'],
                ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '1500000.00'],
            ],
            'open_items' => [
                ['party_ref' => 'C-001', 'control_role' => 'receivables_control', 'kind' => 'invoice', 'document_number' => 'INV-USD', 'document_date' => '2026-03-10', 'amount' => '100', 'currency' => 'USD'],
            ],
        ]);

        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertUnprocessable()->assertJsonValidationErrors(['open_items.0.exchange_rate']);

        $payload['open_items'][0]['exchange_rate'] = '15000';
        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", $payload)->assertCreated();

        expect(DB::table('sl_open_items')->first())->toMatchArray(['currency' => 'USD', 'original_amount' => '100.000000', 'original_functional' => '1500000.000000', 'exchange_rate' => '15000.000000000000']);
    });
});

describe('after the import', function () {
    it('lets the open items be allocated, and reconciles the control accounts at the opening date', function () {
        importOpeningItems()->assertCreated();
        $debit = (string) DB::table('sl_open_items')->where('document_number', 'INV-001')->value('id');
        $credit = (string) DB::table('sl_open_items')->where('document_number', 'CN-001')->value('id');

        $this->actingAs(memberWithRoles($this->companyId, 'preparer'))->postJson("{$this->url}/allocations", ['open_item_id' => $debit, 'counterpart_open_item_id' => $credit, 'amount' => '200000', 'allocation_date' => '2026-04-05'])->assertCreated();

        expect(DB::table('sl_open_items')->where('id', $debit)->value('remaining_amount'))->toBe('800000.000000');

        $runs = collect($this->actingAs($this->admin)->postJson("{$this->url}/reconciliations", ['as_of' => '2026-04-30'])->assertCreated()->json('data'))->keyBy('account_code');

        expect($runs['1121'])->toMatchArray(['ledger_balance' => '1500000.000000', 'detail_total' => '1500000.000000', 'difference' => '0.000000'])
            ->and($runs['2110'])->toMatchArray(['ledger_balance' => '-400000.000000', 'detail_total' => '-400000.000000', 'difference' => '0.000000']);
    });

    it('reconciles at the opening date when the control accounts were not imported', function () {
        $runs = collect($this->actingAs($this->admin)->postJson("{$this->url}/reconciliations", ['as_of' => '2026-04-30'])->assertCreated()->json('data'));

        expect($runs->pluck('difference')->unique()->all())->toBe(['0.000000']);
    });
});
