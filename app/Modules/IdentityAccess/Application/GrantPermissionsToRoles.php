<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

/**
 * Adds catalog permissions to roles that already exist (D5b): a new permission never reaches a role
 * by itself, because roles are company data. The operator names the role and the permissions; the
 * code never decides which role deserves which permission (P-01). Idempotent: a permission the role
 * holds is left alone, and a role that gains nothing is neither touched nor audited.
 */
final class GrantPermissionsToRoles
{
    public function __construct(
        private readonly SyncPermissionCatalog $syncCatalog,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @return list<array{company_id: string, role_id: string, role: string, added: list<string>}> roles that gain something
     *
     * @throws InvalidArgumentException a permission outside the catalog, or no role of that name
     */
    public function __invoke(string $roleName, array $permissions, ?string $companyId = null, bool $dryRun = false): array
    {
        $permissions = array_values(array_unique($permissions));
        $unknown = array_diff($permissions, PermissionCatalog::all());

        if ($permissions === [] || $unknown !== []) {
            throw new InvalidArgumentException($permissions === []
                ? 'Name at least one permission.'
                : 'Not in the permission catalog: '.implode(', ', $unknown));
        }

        return DB::transaction(function () use ($roleName, $permissions, $companyId, $dryRun): array {
            ($this->syncCatalog)();

            $roles = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
                ->orderBy('company_id')
                ->lockForUpdate()
                ->get();

            if ($roles->isEmpty()) {
                throw new InvalidArgumentException("No role named [{$roleName}] found.");
            }

            $ids = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id', 'name');
            $changes = [];

            foreach ($roles as $role) {
                $before = $role->load('permissions')->permissionKeys();
                $added = array_values(array_diff($permissions, $before));

                if ($added === []) {
                    continue;
                }

                $changes[] = ['company_id' => $role->company_id, 'role_id' => $role->id, 'role' => $role->name, 'added' => $added];

                if ($dryRun) {
                    continue;
                }

                $role->permissions()->attach($ids->only($added)->values()->all());
                // Editors holding the old permission set must conflict, as with a change through the API.
                $role->forceFill(['lock_version' => $role->lock_version + 1])->save();

                $after = [...$before, ...$added];
                sort($after);

                $this->audit->record(
                    'role.updated',
                    "Permissions granted to role {$role->name} by an operator.",
                    $role,
                    before: ['permissions' => $before],
                    after: ['permissions' => $after],
                    companyId: $role->company_id,
                );
            }

            return $changes;
        });
    }
}
