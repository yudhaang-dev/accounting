<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Party roles as data of the company (SL-FR-01, ADR-0015).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

describe('importing the roles of the template (X-AC-03)', function () {
    it('lists the roles to review, labelled in the language of the request', function () {
        $keys = collect($this->actingAs($this->admin)->getJson("{$this->url}/party-role-templates")->assertOk()->json('data'));

        expect($keys->pluck('key')->all())->toBe(['customer', 'supplier', 'employee', 'lender', 'shareholder'])
            ->and($keys->firstWhere('key', 'employee')['label'])->toBe('Employee');

        $this->actingAs($this->admin)->withHeader('Accept-Language', 'id')->getJson("{$this->url}/party-role-templates")->assertJsonPath('data.2.label', 'Karyawan');
    });

    it('creates only the roles the company confirmed', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['keys' => ['employee']])->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);
        expect(DB::table('sl_party_roles')->count())->toBe(0);

        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee', 'shareholder']])
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.key', 'employee')->assertJsonPath('data.0.status', 'active');

        expect(DB::table('sl_party_roles')->pluck('key')->sort()->values()->all())->toBe(['employee', 'shareholder'])
            ->and(AuditEntry::where('event', 'party_roles.template_imported')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('refuses a key outside the template or one the company has already, creating none', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee', 'astronaut']])
            ->assertUnprocessable()->assertJsonValidationErrors(['keys.1']);

        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee']])->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['customer', 'employee']])
            ->assertUnprocessable()->assertJsonValidationErrors(['keys']);

        expect(DB::table('sl_party_roles')->count())->toBe(1);
    });
});

describe('the roles of a company', function () {
    it('adds a role of its own, whose key is unique and never changes', function () {
        $role = $this->actingAs($this->admin)->postJson("{$this->url}/party-roles", ['key' => 'mitra_proyek', 'label' => 'Mitra proyek'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.lock_version', 1)->json('data');

        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles", ['key' => 'mitra_proyek', 'label' => 'Lagi'])->assertUnprocessable()->assertJsonValidationErrors(['key']);
        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles", ['key' => 'Mitra Proyek', 'label' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['key']);

        $this->actingAs($this->admin)->patchJson("{$this->url}/party-roles/{$role['id']}", ['lock_version' => 1, 'key' => 'lain', 'label' => 'Mitra kegiatan'])
            ->assertOk()->assertJsonPath('data.key', 'mitra_proyek')->assertJsonPath('data.label', 'Mitra kegiatan')->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->admin)->patchJson("{$this->url}/party-roles/{$role['id']}", ['lock_version' => 1, 'label' => 'Basi'])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->actingAs($this->admin)->deleteJson("{$this->url}/party-roles/{$role['id']}")->assertStatus(405);
    });

    it('deactivates a role that parties hold without taking it from them', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee']])->assertCreated();
        $party = $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Budi', 'roles' => ['employee']])->assertCreated()->json('data');
        $role = $this->actingAs($this->admin)->getJson("{$this->url}/party-roles")->json('data.0');

        $this->actingAs($this->admin)->patchJson("{$this->url}/party-roles/{$role['id']}", ['lock_version' => 1, 'status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->actingAs($this->admin)->getJson("{$this->url}/party-roles?filter[status]=active")->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/parties/{$party['id']}")->assertJsonPath('data.roles', ['employee']);
        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$party['id']}", ['lock_version' => 1, 'name' => 'Budi Santoso', 'roles' => ['employee']])->assertOk()->assertJsonPath('data.roles', ['employee']);

        expect(AuditEntry::where('event', 'party_role.updated')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('keeps the roles apart per company', function () {
        $other = User::factory()->create();
        [$otherCompanyId] = createWorkspaceAs($other, 'Beta');

        $this->actingAs($this->admin)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee']])->assertCreated();

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompanyId}/party-roles")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompanyId}/parties", ['name' => 'Pihak', 'roles' => ['employee']])->assertUnprocessable()->assertJsonValidationErrors(['roles.0']);
    });

    it('lets a viewer read but not manage the roles', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/party-roles")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/party-roles", ['key' => 'x1', 'label' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/party-roles/import-template", ['confirmed' => true, 'keys' => ['employee']])->assertForbidden();
    });
});

describe('the flags this replaces', function () {
    it('has no is_customer or is_supplier on a party any more', function () {
        $party = $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Pihak', 'is_customer' => true])->assertCreated()->json('data');

        expect($party)->not->toHaveKeys(['is_customer', 'is_supplier'])->and($party['roles'])->toBe([]);
    });

    it('turns the flags of existing parties into the roles customer and supplier', function () {
        $migration = require base_path('app/Modules/Subledger/Database/migrations/2026_10_30_000002_create_sl_party_roles_tables.php');
        $migration->down();

        $customer = (string) Str::ulid();
        $both = (string) Str::ulid();
        $now = now();

        foreach ([[$customer, 'Pelanggan', true, false], [$both, 'Dua', true, true]] as [$id, $name, $isCustomer, $isSupplier]) {
            DB::table('sl_parties')->insert(['id' => $id, 'company_id' => $this->companyId, 'name' => $name, 'is_customer' => $isCustomer, 'is_supplier' => $isSupplier, 'status' => 'active', 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        $migration->up();

        $roles = fn (string $id) => DB::table('sl_party_role_assignments as a')->join('sl_party_roles as r', 'r.id', '=', 'a.party_role_id')->where('a.party_id', $id)->pluck('r.key')->sort()->values()->all();

        expect($roles($customer))->toBe(['customer'])->and($roles($both))->toBe(['customer', 'supplier'])
            ->and(DB::table('sl_party_roles')->where('company_id', $this->companyId)->pluck('label', 'key')->all())->toBe(['customer' => 'Pelanggan', 'supplier' => 'Pemasok']);
    });
});
