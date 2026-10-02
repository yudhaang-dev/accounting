<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Creates, changes and deactivates the roles of the current company (IA-FR-02). Roles are data:
 * any name and any set of catalog permissions; nothing here refers to a role by name (P-01). A
 * change applies to every holder from the next request (IA-AC-03). Roles are never deleted, so
 * audit keeps pointing at them (IA-FR-01).
 */
final class ManageRoles
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly SyncPermissionCatalog $syncCatalog,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, permissions?: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $companyId = $this->company->require();
            $this->assertNameAvailable($companyId, $data['name']);

            $role = Role::query()->create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
            ]);
            $this->syncPermissions($role, $data['permissions'] ?? []);

            $this->audit->record(
                'role.created',
                "Role {$role->name} created.",
                $role,
                after: $this->snapshot($role),
                companyId: $companyId,
            );

            return $role->refresh();
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, permissions?: list<string>}  $data
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function update(string $roleId, array $data, int $expectedVersion): Role
    {
        return DB::transaction(function () use ($roleId, $data, $expectedVersion): Role {
            $role = $this->lock($roleId);
            $before = $this->snapshot($role);

            if (isset($data['name']) && $data['name'] !== $role->name) {
                $this->assertNameAvailable($role->company_id, $data['name']);
            }

            $role->updateLocked(array_intersect_key($data, array_flip(['name', 'description'])), $expectedVersion);

            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($role, $data['permissions']);
            }

            $after = $this->snapshot($role);

            if ($before !== $after) {
                // A permission-only change leaves the row untouched, yet stale editors must conflict.
                if ($role->lock_version === $expectedVersion) {
                    $role->forceFill(['lock_version' => $expectedVersion + 1])->save();
                }

                $this->audit->record(
                    'role.updated',
                    "Role {$role->name} updated.",
                    $role,
                    before: $before,
                    after: $after,
                    companyId: $role->company_id,
                );
            }

            return $role->refresh();
        });
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivate(string $roleId): Role
    {
        return $this->setActive($roleId, false, 'role.deactivated');
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivate(string $roleId): Role
    {
        return $this->setActive($roleId, true, 'role.reactivated');
    }

    private function setActive(string $roleId, bool $active, string $event): Role
    {
        return DB::transaction(function () use ($roleId, $active, $event): Role {
            $role = $this->lock($roleId);

            if ($role->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $role->is_active = $active;
            $role->save();

            $this->audit->record(
                $event,
                "Role {$role->name} ".($active ? 'reactivated' : 'deactivated').'.',
                $role,
                before: ['is_active' => ! $active],
                after: ['is_active' => $active],
                companyId: $role->company_id,
            );

            return $role;
        });
    }

    private function lock(string $roleId): Role
    {
        return Role::query()
            ->where('company_id', $this->company->require())
            ->lockForUpdate()
            ->findOrFail($roleId);
    }

    /** @throws ValidationException */
    private function assertNameAvailable(string $companyId, string $name): void
    {
        $taken = Role::query()
            ->where('company_id', $companyId)
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('roles.name_taken', ['name' => $name])]]);
        }
    }

    /** @param  list<string>  $keys */
    private function syncPermissions(Role $role, array $keys): void
    {
        ($this->syncCatalog)();

        $ids = Permission::query()->where('guard_name', 'web')->whereIn('name', $keys)->pluck('id')->all();
        $role->permissions()->sync($ids);
    }

    /** @return array{name: string, description: string|null, is_active: bool, permissions: list<string>} */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'permissions' => $role->load('permissions')->permissionKeys(),
        ];
    }
}
