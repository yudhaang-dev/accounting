<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;

/** @return array<string, string> */
function newWorkspaceBody(string $name = 'Baru'): array
{
    return ['workspace_name' => $name, 'company_name' => "PT {$name}", 'functional_currency' => 'IDR'];
}

/** A member of the company who holds a role with exactly the given permissions. */
function memberWithPermissions(User $admin, string $companyId, array $permissions): User
{
    $role = test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/roles", [
        'name' => 'Pembuat workspace',
        'permissions' => $permissions,
    ])->assertCreated()->json('data.id');

    $user = User::factory()->create();
    Membership::factory()->create(['workspace_id' => Workspace::where('company_id', $companyId)->value('id'), 'user_id' => $user->id]);
    Role::findOrFail($role)->users()->attach($user->id, ['company_id' => $companyId]);

    return $user;
}

describe('who may create a workspace (D2c)', function () {
    it('refuses an account that is neither the first owner nor holds workspaces.create', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.can_create_workspace', false);
        $this->actingAs($user)->postJson('/api/v1/workspaces', newWorkspaceBody())->assertForbidden();

        expect(Workspace::query()->count())->toBe(0);
    });

    it('lets the first owner create workspaces', function () {
        $owner = makePlatformOwner(User::factory()->create());

        $this->actingAs($owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.can_create_workspace', true);
        $this->actingAs($owner)->postJson('/api/v1/workspaces', newWorkspaceBody())->assertCreated();
    });

    it('does not make a member, even a full administrator, entitled to more tenants', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());

        foreach (['viewer', 'accounting_admin'] as $role) {
            $this->actingAs(memberWithRoles($companyId, $role))
                ->postJson('/api/v1/workspaces', newWorkspaceBody('Lain'))->assertForbidden();
        }

        expect(Workspace::query()->count())->toBe(1);
    });

    it('lets a user create workspaces when a role grants workspaces.create', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $creator = memberWithPermissions($admin, $companyId, ['workspaces.create']);

        $this->actingAs($creator)->getJson('/api/v1/me')->assertJsonPath('data.can_create_workspace', true);
        $this->actingAs($creator)->postJson('/api/v1/workspaces', newWorkspaceBody('Tenant baru'))->assertCreated();

        expect(Workspace::query()->count())->toBe(2);
    });

    it('stops honouring the role when the membership is revoked or the role is deactivated', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $creator = memberWithPermissions($admin, $companyId, ['workspaces.create']);

        Membership::where('user_id', $creator->id)->update(['status' => 'revoked']);
        $this->actingAs($creator)->postJson('/api/v1/workspaces', newWorkspaceBody())->assertForbidden();

        Membership::where('user_id', $creator->id)->update(['status' => 'active']);
        $this->actingAs($creator)->postJson('/api/v1/workspaces', newWorkspaceBody())->assertCreated();

        Role::where('company_id', $companyId)->where('name', 'Pembuat workspace')->update(['is_active' => false]);
        $this->actingAs($creator)->postJson('/api/v1/workspaces', newWorkspaceBody('Lagi'))->assertForbidden();
    });

    it('keeps the permission out of the standard roles', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());

        $holders = Role::where('company_id', $companyId)
            ->whereHas('permissions', fn ($query) => $query->where('name', 'workspaces.create'))
            ->count();

        expect($holders)->toBe(0);
    });
});
