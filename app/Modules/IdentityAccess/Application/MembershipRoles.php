<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use Illuminate\Support\Facades\DB;

/**
 * Loads the roles each member holds in a company, in one query, for membership listings.
 */
final class MembershipRoles
{
    /**
     * Sets the `roles` relation of every membership to a list of `{id, name, is_active}` arrays.
     *
     * @param  iterable<Membership>  $memberships
     */
    public function attach(string $companyId, iterable $memberships): void
    {
        $tables = config('permission.table_names');
        $memberships = collect($memberships);

        $rows = DB::table("{$tables['model_has_roles']} as assigned")
            ->join("{$tables['roles']} as role", 'role.id', '=', 'assigned.role_id')
            ->where('assigned.company_id', $companyId)
            ->where('assigned.model_type', (new User)->getMorphClass())
            ->whereIn('assigned.model_id', $memberships->pluck('user_id')->all())
            ->orderBy('role.name')
            ->get(['assigned.model_id', 'role.id', 'role.name', 'role.is_active']);

        $byUser = [];

        foreach ($rows as $row) {
            $byUser[$row->model_id][] = ['id' => $row->id, 'name' => $row->name, 'is_active' => (bool) $row->is_active];
        }

        foreach ($memberships as $membership) {
            $membership->setRelation('roles', collect($byUser[$membership->user_id] ?? []));
        }
    }
}
