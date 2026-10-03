<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function createParty(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/parties', array_merge([
        'name' => 'PT Maju Jaya',
        'is_customer' => true,
    ], $overrides));
}

describe('parties (SL-FR-01)', function () {
    it('creates a customer, a supplier or both, with an optional tax id', function () {
        $customer = createParty(['tax_id' => '01.234.567.8-901.000'])->assertCreated();
        $both = createParty(['name' => 'CV Dua Peran', 'is_customer' => true, 'is_supplier' => true])->assertCreated();
        $supplier = createParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true])->assertCreated();

        expect($customer->json('data'))->toMatchArray([
            'name' => 'PT Maju Jaya', 'is_customer' => true, 'is_supplier' => false,
            'tax_id' => '01.234.567.8-901.000', 'status' => 'active', 'external_refs' => [], 'lock_version' => 1,
        ])
            ->and($both->json('data.is_customer'))->toBeTrue()->and($both->json('data.is_supplier'))->toBeTrue()
            ->and($supplier->json('data.is_customer'))->toBeFalse()->and($supplier->json('data.is_supplier'))->toBeTrue()
            ->and(AuditEntry::where('event', 'party.created')->where('company_id', $this->companyId)->count())->toBe(3);
    });

    it('rejects a party without a role', function () {
        createParty(['is_customer' => false])->assertUnprocessable()->assertJsonValidationErrors(['is_customer']);
        createParty(['is_customer' => false, 'is_supplier' => false])->assertUnprocessable()->assertJsonValidationErrors(['is_customer']);
        $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Tanpa Peran'])->assertUnprocessable();
    });

    it('changes a party, keeps one role, and answers VERSION_CONFLICT for a stale version', function () {
        $party = createParty()->assertCreated()->json('data');

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 1, 'name' => 'PT Maju Mundur', 'is_supplier' => true])
            ->assertOk()->assertJsonPath('data.name', 'PT Maju Mundur')->assertJsonPath('data.is_supplier', true)->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 1, 'name' => 'Basi'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 2, 'is_customer' => false, 'is_supplier' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_customer']);

        $audit = AuditEntry::where('event', 'party.updated')->firstOrFail();
        expect($audit->attribute_changes['old']['name'])->toBe('PT Maju Jaya')->and($audit->attribute_changes['attributes']['name'])->toBe('PT Maju Mundur');
    });

    it('is never deleted, only deactivated and reactivated, and stays listed and readable', function () {
        $id = createParty()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->deleteJson("{$this->url}/parties/{$id}")->assertStatus(405);

        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->admin)->getJson("{$this->url}/parties/{$id}")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->getJson("{$this->url}/parties?filter[status]=inactive")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/parties?filter[status]=active")->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$id}/reactivate")->assertStatus(409);

        expect(AuditEntry::whereIn('event', ['party.deactivated', 'party.reactivated'])->count())->toBe(2);
    });

    it('filters by role, by part of the name, and orders by name', function () {
        createParty(['name' => 'Budi Pemasok', 'is_customer' => false, 'is_supplier' => true])->assertCreated();
        createParty(['name' => 'Agus Pelanggan'])->assertCreated();
        createParty(['name' => 'Citra Dua', 'is_supplier' => true])->assertCreated();

        $names = fn (string $query) => collect($this->actingAs($this->admin)->getJson("{$this->url}/parties{$query}")->assertOk()->json('data'))->pluck('name')->all();

        expect($names(''))->toBe(['Agus Pelanggan', 'Budi Pemasok', 'Citra Dua'])
            ->and($names('?filter[role]=customer'))->toBe(['Agus Pelanggan', 'Citra Dua'])
            ->and($names('?filter[role]=supplier'))->toBe(['Budi Pemasok', 'Citra Dua'])
            ->and($names('?filter[search]=pemasok'))->toBe(['Budi Pemasok'])
            ->and($names('?filter[search]=%25'))->toBe([]);
    });
});

describe('external references (SL-AC-08)', function () {
    it('maps the same reference of one source system to the same party', function () {
        $party = createParty(['external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']]])->assertCreated();

        expect($party->json('data.external_refs'))->toBe([['source_system' => 'erp', 'external_ref' => 'CUST-001']]);

        $found = $this->actingAs($this->admin)->getJson("{$this->url}/parties?filter[source_system]=erp&filter[external_ref]=CUST-001")->assertOk();

        expect($found->json('data'))->toHaveCount(1)
            ->and($found->json('data.0.id'))->toBe($party->json('data.id'));

        $this->actingAs($this->admin)->getJson("{$this->url}/parties?filter[source_system]=erp&filter[external_ref]=CUST-404")->assertOk()->assertJsonCount(0, 'data');
    });

    it('rejects a reference of the same source system that belongs to another party', function () {
        createParty(['external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']]])->assertCreated();

        createParty(['name' => 'Pihak Lain', 'external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['external_refs.0.external_ref']);

        expect($this->actingAs($this->admin)->getJson("{$this->url}/parties")->json('data'))->toHaveCount(1);
    });

    it('treats the same reference of another source system as a different party', function () {
        $erp = createParty(['external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']]])->assertCreated()->json('data.id');
        $shop = createParty(['name' => 'Pihak Toko', 'external_refs' => [['source_system' => 'shop', 'external_ref' => 'CUST-001']]])->assertCreated()->json('data.id');

        $lookup = fn (string $system) => $this->actingAs($this->admin)
            ->getJson("{$this->url}/parties?filter[source_system]={$system}&filter[external_ref]=CUST-001")->assertOk()->json('data.0.id');

        expect($erp)->not->toBe($shop)->and($lookup('erp'))->toBe($erp)->and($lookup('shop'))->toBe($shop);
    });

    it('adds references on update, ignores ones it already has, and rejects ones of another party', function () {
        $first = createParty(['external_refs' => [['source_system' => 'erp', 'external_ref' => 'A-1']]])->assertCreated()->json('data');
        $second = createParty(['name' => 'Pihak Kedua', 'external_refs' => [['source_system' => 'erp', 'external_ref' => 'B-1']]])->assertCreated()->json('data');

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$first['id']}", [
            'lock_version' => 1,
            'external_refs' => [['source_system' => 'erp', 'external_ref' => 'A-1'], ['source_system' => 'shop', 'external_ref' => 'S-9']],
        ])->assertOk()->assertJsonPath('data.external_refs', [
            ['source_system' => 'erp', 'external_ref' => 'A-1'],
            ['source_system' => 'shop', 'external_ref' => 'S-9'],
        ]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$first['id']}", [
            'lock_version' => 2,
            'name' => 'Tidak Boleh Berubah',
            'external_refs' => [['source_system' => 'erp', 'external_ref' => 'B-1']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['external_refs.0.external_ref']);

        $this->actingAs($this->admin)->getJson("{$this->url}/parties/{$first['id']}")->assertJsonPath('data.name', 'PT Maju Jaya')->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->admin)->getJson("{$this->url}/parties/{$second['id']}")->assertJsonPath('data.external_refs.0.external_ref', 'B-1');
    });

    it('validates the shape of a reference', function () {
        createParty(['external_refs' => [['source_system' => 'ERP System', 'external_ref' => 'X']]])->assertUnprocessable()->assertJsonValidationErrors(['external_refs.0.source_system']);
        createParty(['external_refs' => [['source_system' => 'erp']]])->assertUnprocessable()->assertJsonValidationErrors(['external_refs.0.external_ref']);
        $this->actingAs($this->admin)->getJson("{$this->url}/parties?filter[source_system]=erp")->assertUnprocessable();
    });

    it('keeps references apart per company', function () {
        $other = User::factory()->create();
        [$otherCompanyId] = createWorkspaceAs($other, 'Beta');

        createParty(['external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']]])->assertCreated();

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompanyId}/parties", [
            'name' => 'Pihak Beta', 'is_supplier' => true, 'external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']],
        ])->assertCreated();

        $id = createParty(['name' => 'Pihak Lain'])->assertCreated()->json('data.id');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompanyId}/parties/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson("{$this->url}/parties")->assertForbidden();
    });
});

describe('access', function () {
    it('lets a viewer read but not manage parties', function () {
        $id = createParty()->assertCreated()->json('data.id');
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/parties")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/parties/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/parties", ['name' => 'X', 'is_customer' => true])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/parties/{$id}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/parties/{$id}/deactivate")->assertForbidden();
    });
});
