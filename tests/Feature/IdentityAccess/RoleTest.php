<?php

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\AuditEntry;

function rolesUrl(string $companyId, string $suffix = ''): string
{
    return "/api/v1/companies/{$companyId}/roles{$suffix}";
}

describe('GET /permissions (IA-FR-02)', function () {
    it('lists the catalog as resource and action', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());

        $response = $this->actingAs($admin)->getJson("/api/v1/companies/{$companyId}/permissions")->assertOk();

        expect(array_column($response->json('data'), 'key'))->toBe(PermissionCatalog::all());
        $response->assertJsonFragment(['key' => 'entries.approve', 'resource' => 'entries', 'action' => 'approve']);
    });

    it('needs roles.read', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $preparer = memberWithRoles($companyId, 'preparer');

        $this->actingAs($preparer)->getJson("/api/v1/companies/{$companyId}/permissions")->assertForbidden();
    });
});

describe('roles API (IA-FR-02, IA-AC-03)', function () {
    it('lists the seeded roles with their permissions and filters by status', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        Role::where('company_id', $companyId)->where('name', 'viewer')->update(['is_active' => false]);

        $all = $this->actingAs($admin)->getJson(rolesUrl($companyId))->assertOk();
        $active = $this->actingAs($admin)->getJson(rolesUrl($companyId, '?filter[is_active]=1'))->assertOk();

        expect($all->json('data'))->toHaveCount(count(config('role-templates.templates')))
            ->and(collect($active->json('data'))->pluck('name')->all())->not->toContain('viewer')
            ->and(collect($all->json('data'))->firstWhere('name', 'auditor')['permissions'])->toContain('audit.read');
    });

    it('creates a role with catalog permissions and audits it', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());

        $response = $this->actingAs($admin)->postJson(rolesUrl($companyId), [
            'name' => 'cashier',
            'description' => 'Posts cash entries.',
            'permissions' => ['entries.read', 'entries.create'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'cashier')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.permissions', ['entries.create', 'entries.read'])
            ->assertJsonPath('data.lock_version', 1);

        $this->actingAs($admin)->getJson(rolesUrl($companyId, '/'.$response->json('data.id')))
            ->assertOk()->assertJsonPath('data.name', 'cashier');

        expect(AuditEntry::where('company_id', $companyId)->where('event', 'role.created')->count())->toBe(1);
    });

    it('rejects unknown permissions, duplicate names and missing names', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());

        $this->actingAs($admin)->postJson(rolesUrl($companyId), ['name' => 'x', 'permissions' => ['entries.fly']])
            ->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);
        $this->actingAs($admin)->postJson(rolesUrl($companyId), ['name' => 'viewer'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->postJson(rolesUrl($companyId), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('allows the same role name in another company', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs($otherAdmin = User::factory()->create(), 'Other');

        $this->actingAs($admin)->postJson(rolesUrl($companyId), ['name' => 'cashier'])->assertCreated();
        $this->actingAs($otherAdmin)->postJson(rolesUrl($otherCompanyId), ['name' => 'cashier'])->assertCreated();
    });

    it('applies changed permissions to every holder from the next request (IA-AC-03)', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $one = memberWithRoles($companyId, 'viewer');
        $two = memberWithRoles($companyId, 'viewer');
        $contexts = app(AccessContexts::class);
        $viewer = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();

        expect($contexts->forUser($one->id, $companyId)->can('reports.export'))->toBeFalse();

        $this->actingAs($admin)->patchJson(rolesUrl($companyId, "/{$viewer->id}"), [
            'lock_version' => $viewer->lock_version,
            'permissions' => [...$viewer->permissions->pluck('name')->all(), 'reports.export'],
        ])->assertOk()->assertJsonPath('data.lock_version', 1 + 1);

        expect($contexts->forUser($one->id, $companyId)->can('reports.export'))->toBeTrue()
            ->and($contexts->forUser($two->id, $companyId)->can('reports.export'))->toBeTrue();

        $audit = AuditEntry::where('company_id', $companyId)->where('event', 'role.updated')->firstOrFail();
        expect($audit->attribute_changes['attributes']['permissions'])->toContain('reports.export')
            ->and($audit->attribute_changes['old']['permissions'])->not->toContain('reports.export');
    });

    it('renames a role without changing what its holders may do', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        $viewer = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();

        $this->actingAs($admin)->patchJson(rolesUrl($companyId, "/{$viewer->id}"), [
            'lock_version' => $viewer->lock_version,
            'name' => 'senior-reader',
        ])->assertOk()->assertJsonPath('data.name', 'senior-reader');

        $this->actingAs($member)->getJson("/api/v1/companies/{$companyId}/accounts")->assertOk();
    });

    it('refuses a stale edit and a duplicate rename', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $viewer = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();
        $url = rolesUrl($companyId, "/{$viewer->id}");

        $this->actingAs($admin)->patchJson($url, ['lock_version' => 1, 'description' => 'first'])->assertOk();
        $this->actingAs($admin)->patchJson($url, ['lock_version' => 1, 'description' => 'second'])
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->actingAs($admin)->patchJson($url, ['lock_version' => 2, 'name' => 'auditor'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('deactivates and reactivates a role; an inactive role grants nothing', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        $viewer = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();
        $accounts = "/api/v1/companies/{$companyId}/accounts";

        $this->actingAs($admin)->postJson(rolesUrl($companyId, "/{$viewer->id}/deactivate"))
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->postJson(rolesUrl($companyId, "/{$viewer->id}/deactivate"))
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
        $this->actingAs($member)->getJson($accounts)->assertForbidden();

        $this->actingAs($admin)->postJson(rolesUrl($companyId, "/{$viewer->id}/reactivate"))
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->actingAs($member)->getJson($accounts)->assertOk();

        expect(AuditEntry::where('company_id', $companyId)->whereIn('event', ['role.deactivated', 'role.reactivated'])->count())->toBe(2);
    });

    it('requires roles.manage to write and roles.read to read', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $role = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();

        $this->actingAs($viewer)->getJson(rolesUrl($companyId))->assertForbidden();
        $this->actingAs($viewer)->postJson(rolesUrl($companyId), ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson(rolesUrl($companyId, "/{$role->id}/deactivate"))->assertForbidden();
    });

    it('does not reach roles of another company', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs(User::factory()->create(), 'Other');
        $foreign = Role::where('company_id', $otherCompanyId)->firstOrFail();

        $this->actingAs($admin)->getJson(rolesUrl($companyId, "/{$foreign->id}"))->assertNotFound();
        $this->actingAs($admin)->patchJson(rolesUrl($companyId, "/{$foreign->id}"), ['lock_version' => 1, 'name' => 'x'])->assertNotFound();
        $this->actingAs($admin)->postJson(rolesUrl($companyId, "/{$foreign->id}/deactivate"))->assertNotFound();
        $this->actingAs($admin)->getJson(rolesUrl($otherCompanyId))->assertForbidden();
    });
});
