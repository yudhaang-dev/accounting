<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\WorkspaceCreators;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use Illuminate\Support\Facades\DB;

final class EloquentWorkspaceCreators implements WorkspaceCreators
{
    public const PERMISSION = 'workspaces.create';

    public function canCreate(int $userId): bool
    {
        if (DB::table('ia_platform_owners')->where('user_id', $userId)->exists()) {
            return true;
        }

        $tables = config('permission.table_names');

        $companyIds = DB::table("{$tables['model_has_roles']} as assigned")
            ->join("{$tables['roles']} as role", 'role.id', '=', 'assigned.role_id')
            ->join("{$tables['role_has_permissions']} as grants", 'grants.role_id', '=', 'role.id')
            ->join("{$tables['permissions']} as permission", 'permission.id', '=', 'grants.permission_id')
            ->where('assigned.model_type', (new User)->getMorphClass())
            ->where('assigned.model_id', $userId)
            ->where('role.is_active', true)
            ->where('permission.name', self::PERMISSION)
            ->pluck('assigned.company_id')
            ->all();

        if ($companyIds === []) {
            return false;
        }

        // A role only counts while the membership it hangs on still grants access.
        return Workspace::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('id', Membership::query()->grantingAccess()->where('user_id', $userId)->select('workspace_id'))
            ->exists();
    }
}
