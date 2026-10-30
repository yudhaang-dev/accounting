<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function createParty(array $overrides = []): TestResponse
{
    $body = array_merge(['name' => 'PT Maju Jaya', 'roles' => ['customer']], $overrides);
    ensurePartyRoles($body['roles']);

    return test()->actingAs(test()->admin)->postJson(test()->url.'/parties', $body);
}

describe('parties (SL-FR-01)', function () {
    it('creates a party with the roles it holds, with an optional tax id', function () {
        $customer = createParty(['tax_id' => '01.234.567.8-901.000'])->assertCreated();
        $both = createParty(['name' => 'CV Dua Peran', 'roles' => ['supplier', 'customer']])->assertCreated();
        $supplier = createParty(['name' => 'UD Pemasok', 'roles' => ['supplier']])->assertCreated();

        expect($customer->json('data'))->toMatchArray([
            'name' => 'PT Maju Jaya', 'roles' => ['customer'],
            'tax_id' => '01.234.567.8-901.000', 'status' => 'active', 'external_refs' => [], 'lock_version' => 1,
        ])
            ->and($both->json('data.roles'))->toBe(['customer', 'supplier'])
            ->and($supplier->json('data.roles'))->toBe(['supplier'])
            ->and(AuditEntry::where('event', 'party.created')->where('company_id', $this->companyId)->count())->toBe(3);
    });

    it('keeps the group company a party stands for as an opaque reference (GL-FR-15)', function () {
        $other = (string) Str::ulid();

        $party = createParty(['counterparty_company_id' => $other])->assertCreated();

        expect($party->json('data.counterparty_company_id'))->toBe($other);

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party->json('data.id')}", ['lock_version' => 1, 'counterparty_company_id' => null])
            ->assertOk()
            ->assertJsonPath('data.counterparty_company_id', null);

        createParty(['counterparty_company_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['counterparty_company_id']);
    });

    it('takes a party without a role, and rejects a role the company does not have or has deactivated', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Tanpa Peran'])->assertCreated()->assertJsonPath('data.roles', []);

        $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Peran Asing', 'roles' => ['astronaut']])->assertUnprocessable()->assertJsonValidationErrors(['roles.0']);

        ensurePartyRoles(['lender']);
        $lender = collect($this->actingAs($this->admin)->getJson("{$this->url}/party-roles")->json('data'))->firstWhere('key', 'lender');
        $this->actingAs($this->admin)->patchJson("{$this->url}/party-roles/{$lender['id']}", ['lock_version' => 1, 'status' => 'inactive'])->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Peran Mati', 'roles' => ['lender']])->assertUnprocessable()->assertJsonValidationErrors(['roles.0']);
    });

    it('changes a party and its roles, and answers VERSION_CONFLICT for a stale version', function () {
        ensurePartyRoles(['supplier']);
        $party = createParty()->assertCreated()->json('data');

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 1, 'name' => 'PT Maju Mundur', 'roles' => ['customer', 'supplier']])
            ->assertOk()->assertJsonPath('data.name', 'PT Maju Mundur')->assertJsonPath('data.roles', ['customer', 'supplier'])->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 1, 'name' => 'Basi'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        // The roles are the whole set, and changing only them is a new version too.
        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 2, 'roles' => ['supplier']])
            ->assertOk()->assertJsonPath('data.roles', ['supplier'])->assertJsonPath('data.lock_version', 3);
        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 3, 'name' => 'PT Maju Mundur'])
            ->assertOk()->assertJsonPath('data.roles', ['supplier']);

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
        createParty(['name' => 'Budi Pemasok', 'roles' => ['supplier']])->assertCreated();
        createParty(['name' => 'Agus Pelanggan'])->assertCreated();
        createParty(['name' => 'Citra Dua', 'roles' => ['customer', 'supplier']])->assertCreated();

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
            'name' => 'Pihak Beta', 'external_refs' => [['source_system' => 'erp', 'external_ref' => 'CUST-001']],
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
        $this->actingAs($viewer)->postJson("{$this->url}/parties", ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/parties/{$id}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/parties/{$id}/deactivate")->assertForbidden();
    });
});
