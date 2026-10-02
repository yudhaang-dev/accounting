<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');

    $this->typeId = $this->actingAs($this->admin)
        ->postJson("{$this->url}/transaction-types", ['key' => 'credit_sale', 'label' => 'Penjualan Kredit'])
        ->assertCreated()->json('data.id');
});

function structuredBody(?array $fields = null, string $mode = 'structured'): array
{
    return [
        'mode' => $mode,
        'fields' => $fields ?? [
            ['name' => 'invoice_date', 'type' => 'date', 'label' => 'Tanggal faktur'],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
            ['name' => 'note', 'type' => 'text', 'label' => 'Catatan', 'required' => false],
        ],
    ];
}

function draftVersion(?array $body = null): int
{
    $versions = test()->actingAs(test()->admin)
        ->postJson(test()->url.'/transaction-types/'.test()->typeId.'/versions', $body ?? structuredBody())
        ->assertCreated()->json('data.versions');

    return (int) collect($versions)->max('version');
}

function versionAction(int $version, string $action)
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId."/versions/{$version}/{$action}");
}

it('drafts a structured version with the next number and publishes it as JSON Schema (TE-AC-01)', function () {
    $number = draftVersion();

    expect($number)->toBe(2);

    $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}")
        ->assertJsonPath('data.versions.1.status', 'draft')
        ->assertJsonPath('data.versions.1.fields.1.name', 'total');

    versionAction($number, 'publish')->assertOk()->assertJsonPath('data.versions.1.status', 'published');

    $schema = $this->actingAs($this->preparer)
        ->getJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/schema")
        ->assertOk()->json('data');

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['invoice_date', 'total'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['invoice_date']['format'])->toBe('date')
        ->and($schema['properties']['total']['pattern'])->toBe('^[0-9]+(\.[0-9]+)?$')
        ->and($schema['properties']['total']['title'])->toBe('Total');
});

it('has no schema for a free-lines version', function () {
    $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-types/{$this->typeId}/versions/1/schema")
        ->assertOk()->assertJsonPath('data', null);
});

it('keeps old versions intact when a new version is published (TE-AC-01)', function () {
    $number = draftVersion();
    versionAction($number, 'publish')->assertOk();

    $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}")
        ->assertJsonPath('data.versions.0.mode', 'free_lines')
        ->assertJsonPath('data.versions.0.status', 'published')
        ->assertJsonPath('data.versions.0.fields', null);
});

it('edits a draft but not a published version', function () {
    $number = draftVersion();

    $this->actingAs($this->admin)
        ->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", structuredBody([['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah']]))
        ->assertOk()->assertJsonPath('data.versions.1.fields.0.label', 'Jumlah');

    versionAction($number, 'publish')->assertOk();

    $this->actingAs($this->admin)
        ->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", structuredBody())
        ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    versionAction($number, 'publish')->assertConflict();
});

it('rejects invalid field definitions with an error per field', function () {
    $post = fn (array $fields, string $mode = 'structured') => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", structuredBody($fields, $mode));

    $post([['name' => 'total', 'type' => 'amount', 'label' => 'A'], ['name' => 'total', 'type' => 'date', 'label' => 'B']])
        ->assertUnprocessable()->assertJsonValidationErrors(['fields.1.name']);

    $post([['name' => 'Total', 'type' => 'amount', 'label' => 'A']])->assertUnprocessable()->assertJsonValidationErrors(['fields.0.name']);
    $post([['name' => 'total', 'type' => 'formula', 'label' => 'A']])->assertUnprocessable()->assertJsonValidationErrors(['fields.0.type']);
    $post([['name' => 'dept', 'type' => 'dimension', 'label' => 'A', 'dimension_type' => 'nope']])->assertUnprocessable()->assertJsonValidationErrors(['fields.0.dimension_type']);
    $post([['name' => 'acc', 'type' => 'account', 'label' => 'A', 'account_ids' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ']]])->assertUnprocessable()->assertJsonValidationErrors(['fields.0.account_ids']);
    $post([['name' => 'x', 'type' => 'text', 'label' => 'A']], 'free_lines')->assertUnprocessable();
});

it('accepts account and dimension fields that reference what the company has', function () {
    $sales = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '4110')->value('id');

    $number = draftVersion(structuredBody([
        ['name' => 'revenue', 'type' => 'account', 'label' => 'Pendapatan', 'account_ids' => [$sales]],
        ['name' => 'dept', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department'],
    ]));
    versionAction($number, 'publish')->assertOk();

    $schema = $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/schema")->json('data');

    expect($schema['properties']['revenue']['enum'])->toBe([$sales])
        ->and($schema['properties']['dept']['x-dimension-type'])->toBe('department');
});

it('does not publish a structured version without fields', function () {
    $number = draftVersion(structuredBody([]));

    versionAction($number, 'publish')->assertUnprocessable()->assertJsonValidationErrors(['fields']);
});

function versionedEntry(array $overrides = []): array
{
    $id = fn (string $code): string => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');

    return array_merge([
        'transaction_type_key' => 'credit_sale',
        'accounting_date' => '2026-03-15',
        'description' => 'Penjualan',
        'lines' => [
            ['account_id' => $id('1112'), 'debit' => '100.00'],
            ['account_id' => $id('4110'), 'credit' => '100.00'],
        ],
    ], $overrides);
}

it('retires a published version and refuses new entries on it (TE-AC-06)', function () {
    $entryId = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", versionedEntry())->assertCreated()->json('data.id');

    versionAction(1, 'retire')->assertOk()->assertJsonPath('data.versions.0.status', 'retired');

    $this->actingAs($this->preparer)->postJson("{$this->url}/entries", versionedEntry(['transaction_type_version' => 1]))
        ->assertUnprocessable()->assertJsonPath('code', 'TRANSACTION_TYPE_VERSION_RETIRED');

    $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$entryId}")->assertOk()->assertJsonPath('data.transaction_type_version', 1);

    versionAction(1, 'retire')->assertConflict();
});

it('does not take entries on a structured version yet', function () {
    $number = draftVersion();
    versionAction($number, 'publish')->assertOk();
    versionAction(1, 'retire')->assertOk();

    $this->actingAs($this->preparer)->postJson("{$this->url}/entries", versionedEntry())
        ->assertUnprocessable()->assertJsonValidationErrors(['transaction_type_version']);
});

it('lets only policy managers change versions', function () {
    $this->actingAs($this->preparer)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", structuredBody())->assertForbidden();
});
