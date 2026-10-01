<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccessContext;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reads effective permissions straight from the role tables on every call: no cache and no global
 * team state, so a changed role applies from the next request (IA-AC-03).
 */
final class EffectiveAccessContexts implements AccessContexts
{
    public const REQUEST_ATTRIBUTE = 'access_context';

    public function forUser(int $userId, string $companyId): AccessContext
    {
        return new AccessContext($userId, $companyId, $this->permissionsByCompany($userId, [$companyId])[$companyId] ?? []);
    }

    public function current(Request $request): AccessContext
    {
        $context = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $context instanceof AccessContext
            ? $context
            : throw new LogicException('No access context: the route is not company-scoped.');
    }

    /**
     * @param  list<string>  $companyIds
     * @return array<string, list<string>> sorted permission keys by company ID
     */
    public function permissionsByCompany(int $userId, array $companyIds): array
    {
        $tables = config('permission.table_names');

        $rows = DB::table("{$tables['model_has_roles']} as assigned")
            ->join("{$tables['roles']} as role", 'role.id', '=', 'assigned.role_id')
            ->join("{$tables['role_has_permissions']} as grants", 'grants.role_id', '=', 'role.id')
            ->join("{$tables['permissions']} as permission", 'permission.id', '=', 'grants.permission_id')
            ->where('assigned.model_type', (new User)->getMorphClass())
            ->where('assigned.model_id', $userId)
            ->whereIn('assigned.company_id', $companyIds)
            ->where('role.is_active', true)
            ->distinct()
            ->orderBy('permission.name')
            ->get(['assigned.company_id', 'permission.name']);

        $byCompany = array_fill_keys($companyIds, []);

        foreach ($rows as $row) {
            $byCompany[$row->company_id][] = $row->name;
        }

        return $byCompany;
    }
}
