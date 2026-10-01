<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\Audit;
use LogicException;
use Spatie\Permission\Models\Permission;

/**
 * Creates the roles of config/role-templates.php for a company (IA-FR-02). Roles that already
 * exist are left as the company changed them. The templates are read generically; no role is
 * ever referred to by name here (P-01).
 */
final class SeedCompanyRoles
{
    public function __construct(
        private readonly SyncPermissionCatalog $syncCatalog,
        private readonly Audit $audit,
    ) {}

    /**
     * @return list<Role> roles flagged `grant_to_creator`, for the caller to assign
     */
    public function __invoke(string $companyId): array
    {
        ($this->syncCatalog)();

        /** @var list<array{name: string, description: string, grant_to_creator: bool, permissions: list<string>}> $templates */
        $templates = config('role-templates.templates');
        $permissionIds = Permission::query()->where('guard_name', 'web')->pluck('id', 'name');
        $forCreator = [];
        $created = 0;

        foreach ($templates as $template) {
            $unknown = array_diff($template['permissions'], PermissionCatalog::all());

            if ($unknown !== []) {
                throw new LogicException('Role template ['.$template['name'].'] uses permissions outside the catalog: '.implode(', ', $unknown));
            }

            $role = Role::query()->firstOrCreate(
                ['company_id' => $companyId, 'name' => $template['name'], 'guard_name' => 'web'],
                ['description' => $template['description']],
            );

            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync($permissionIds->only($template['permissions'])->values()->all());
                $created++;
            }

            if ($template['grant_to_creator']) {
                $forCreator[] = $role;
            }
        }

        $this->audit->record(
            'roles.seeded',
            'Roles seeded from templates.',
            properties: ['created' => $created],
            companyId: $companyId,
        );

        return $forCreator;
    }
}
