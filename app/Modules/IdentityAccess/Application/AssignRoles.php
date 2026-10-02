<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the roles a user holds in one company (IA-FR-02). Callers have already checked that the
 * role IDs belong to the company; the composite foreign key backs that up (P-05).
 */
final class AssignRoles
{
    /**
     * @param  list<string>  $roleIds
     * @return bool whether the set of roles changed
     */
    public function __invoke(int $userId, string $companyId, array $roleIds): bool
    {
        $morph = (new User)->getMorphClass();
        $scope = fn (): Builder => DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $morph)
            ->where('model_id', $userId)
            ->where('company_id', $companyId);

        /** @var list<string> $current */
        $current = $scope()->pluck('role_id')->all();
        $wanted = array_values(array_unique($roleIds));
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        if ($added === [] && $removed === []) {
            return false;
        }

        $scope()->whereIn('role_id', $removed)->delete();

        DB::table(config('permission.table_names.model_has_roles'))->insert(array_map(
            fn (string $roleId): array => [
                'role_id' => $roleId,
                'model_type' => $morph,
                'model_id' => $userId,
                'company_id' => $companyId,
            ],
            $added,
        ));

        return true;
    }
}
