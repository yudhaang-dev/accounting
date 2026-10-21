<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// Data scope of an integration client (IA-FR-03, IA-FR-06, X-AC-01). The helpers are those of
// DataScopeTest, IntegrationClientTest and FinancialEventTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $department = scopeSeededType($this->url, 'department');
    $this->sale = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'SALES', 'name' => 'Sales']);
    $this->saleId = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'SALES-ID', 'name' => 'Sales Indonesia', 'parent_id' => $this->sale]);
    $this->finance = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'FIN', 'name' => 'Finance']);

    // Every line of the type takes the department, as a scoped sender needs: a line without it lies outside the scope.
    $bank = scopeAccountId($this->companyId, '1112');
    $sales = scopeAccountId($this->companyId, '4110');
    $typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'sale_event', 'label' => 'Penjualan'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$typeId}/versions", [
        'mode' => 'structured',
        'fields' => [
            ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
            ['name' => 'dept', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'fixed', 'account_id' => $bank], 'apply_dimensions' => true, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'fixed', 'account_id' => $sales], 'apply_dimensions' => true, 'reuses_field' => true],
        ],
    ])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$typeId}/versions/2/publish")->assertOk();
});

function scopedClient(array $dataScope, array $overrides = []): array
{
    return registerClient(array_merge([
        'name' => 'Kasir', 'source_system' => 'pos',
        'scopes' => ['financial-events:write', 'financial-events:read:self'],
        'transaction_type_keys' => ['sale_event'],
        'data_scope' => $dataScope,
    ], $overrides));
}

function saleEvent(string $token, ?string $departmentId, string $sourceId = 'sale-1')
{
    return evSend('POST', test()->url.'/financial-events', [
        'transaction_type' => ['key' => 'sale_event'],
        'accounting_date' => '2026-03-31',
        'currency' => 'IDR',
        'amounts' => ['total' => '1000'],
        'dimensions' => ['department' => $departmentId],
        'references' => ['source_transaction_id' => $sourceId],
    ], $token);
}

describe('registering a client with a data scope', function () {
    it('stores the scope, shows it and audits it', function () {
        $client = scopedClient([$this->sale]);

        expect($client['data_scope'])->toBe([$this->sale]);
        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients/{$client['id']}")->assertOk()->assertJsonPath('data.data_scope', [$this->sale]);
        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients")->assertOk()->assertJsonPath('data.0.data_scope', [$this->sale]);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.created')->latest('id')->first();
        expect($audit->attribute_changes['attributes']['data_scope'])->toBe([$this->sale]);
    });

    it('is unrestricted without one', function () {
        expect(scopedClient([])['data_scope'])->toBe([]);
    });

    it('refuses a value that is not a dimension value of the company', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients", [
            'name' => 'Kasir', 'source_system' => 'pos', 'scopes' => ['financial-events:write'], 'data_scope' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        ])->assertUnprocessable()->assertJson(['code' => 'DIMENSION_INVALID']);

        expect(DB::table('ia_integration_clients')->count())->toBe(0);
    });
});

describe('what a limited client can send and read (X-AC-01)', function () {
    it('sends for the value it is limited to and for its descendants', function () {
        $token = evToken(scopedClient([$this->sale]));

        saleEvent($token, $this->sale, 'sale-1')->assertCreated();
        saleEvent($token, $this->saleId, 'sale-2')->assertCreated();

        expect(DB::table('te_entries')->where('company_id', $this->companyId)->count())->toBe(2);
    });

    it('is refused for another value, or for none, and nothing is recorded', function () {
        $token = evToken(scopedClient([$this->sale]));

        saleEvent($token, $this->finance, 'sale-1')->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        saleEvent($token, null, 'sale-2')->assertForbidden();

        expect(DB::table('te_entries')->where('company_id', $this->companyId)->count())->toBe(0);
    });

    it('does not limit a client that has no scope', function () {
        $token = evToken(scopedClient([]));

        saleEvent($token, $this->finance, 'sale-1')->assertCreated();
        saleEvent($token, null, 'sale-2')->assertCreated();
    });

    it('no longer finds an event once the scope excludes it', function () {
        $client = scopedClient([]);
        $token = evToken($client);
        $event = saleEvent($token, $this->finance)->assertCreated()->json('data');

        evSend('GET', "{$this->url}/financial-events/{$event['event_id']}", [], $token)->assertOk();

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'data_scope' => [$this->sale]])->assertOk();

        evSend('GET', "{$this->url}/financial-events/{$event['event_id']}", [], $token)->assertNotFound();
    });
});

describe('changing the scope of a client', function () {
    it('replaces it, bumps the version, audits it and conflicts for a stale editor', function () {
        $client = scopedClient([$this->sale]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'data_scope' => [$this->finance]])
            ->assertOk()->assertJsonPath('data.data_scope', [$this->finance])->assertJsonPath('data.lock_version', 2);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.updated')->latest('id')->first();
        expect($audit->attribute_changes['old']['data_scope'])->toBe([$this->sale])
            ->and($audit->attribute_changes['attributes']['data_scope'])->toBe([$this->finance]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'data_scope' => []])
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);
    });

    it('removes the limit with an empty list, and leaves it alone when the field is not sent', function () {
        $client = scopedClient([$this->sale]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'name' => 'Kasir 2'])
            ->assertOk()->assertJsonPath('data.data_scope', [$this->sale]);
        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 2, 'data_scope' => []])
            ->assertOk()->assertJsonPath('data.data_scope', []);
    });
});

describe('a user who is limited', function () {
    beforeEach(function () {
        $this->limited = scopedMember('accounting_admin', [$this->sale]);
        $this->client = scopedClient([$this->sale]);
    });

    it('cannot register, change or give new credentials to a client, which could reach what they cannot', function () {
        $this->actingAs($this->limited)->postJson("{$this->url}/integration-clients", [
            'name' => 'Other', 'source_system' => 'other', 'scopes' => ['financial-events:write'],
        ])->assertForbidden();
        $this->actingAs($this->limited)->patchJson("{$this->url}/integration-clients/{$this->client['id']}", ['lock_version' => 1, 'data_scope' => []])->assertForbidden();
        $this->actingAs($this->limited)->postJson("{$this->url}/integration-clients/{$this->client['id']}/rotate-secret")->assertForbidden();

        expect(DB::table('ia_integration_client_data_scopes')->count())->toBe(1);
    });

    it('can still revoke a client, which only takes access away', function () {
        $this->actingAs($this->limited)->postJson("{$this->url}/integration-clients/{$this->client['id']}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
    });
});
