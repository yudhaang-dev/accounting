<?php

namespace App\Modules\GeneralLedger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use Illuminate\Http\JsonResponse;

class AccountRoleController
{
    /**
     * Every account role of the ledger with the account that plays it now, or `null` when it is not
     * mapped. `valid` is false when that account no longer qualifies (inactive, for example), and
     * `required` names the situation in which a missing mapping blocks a feature.
     */
    public function index(AccountRoles $roles, AccountDirectory $accounts, string $companyId): JsonResponse
    {
        $mapped = $roles->all($companyId);
        $data = [];

        foreach (AccountRole::cases() as $role) {
            $mapping = $mapped[$role->value] ?? null;

            $data[] = [
                'key' => $role->value,
                'account_id' => $mapping?->accountId,
                'effective_date' => $mapping?->effectiveDate,
                'valid' => $mapping !== null && $accounts->roleTargetProblems($companyId, $mapping->accountId, $role->allowedClasses()) === [],
                'required' => $role === AccountRole::RetainedEarnings ? 'year_end_close' : 'foreign_currency',
                'allowed_classes' => array_map(fn (AccountClass $class): string => $class->value, $role->allowedClasses()),
                // The version of the mapping (0 when none), named as the concurrency field (API-TR-14).
                'lock_version' => $mapping->version ?? 0,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Maps the role to an account as a new version; the change is audited. The account must be an active
     * leaf of the company in a class the role accepts. `lock_version` is the one you read (0 when the role
     * is not mapped yet) and `effective_date` defaults to today.
     */
    public function update(UpdateAccountRoleRequest $request, AccountRoles $roles, string $companyId, string $role): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, account_id: string, effective_date?: string|null} $data */
        $data = $request->validated();

        $mapping = $roles->change($companyId, AccountRole::from($role), $data['account_id'], (int) $data['lock_version'], $user->id, $data['effective_date'] ?? null);

        return response()->json(['data' => [
            'key' => $mapping->role->value,
            'account_id' => $mapping->accountId,
            'effective_date' => $mapping->effectiveDate,
            'lock_version' => $mapping->version,
        ]]);
    }
}
