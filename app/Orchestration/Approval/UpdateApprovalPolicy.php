<?php

namespace App\Orchestration\Approval;

use App\Modules\IdentityAccess\Contracts\Permissions;
use App\Modules\TransactionEntry\Contracts\ApprovalPolicies;
use App\Modules\TransactionEntry\Contracts\ApprovalPolicyData;
use App\Modules\TransactionEntry\Contracts\ApprovalRule;
use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
use App\Orchestration\Ledger\UpdateLedgerPolicy;
use App\Platform\Api\ApiException;
use Illuminate\Validation\ValidationException;

/**
 * Changes the approval policy after checking what the entry module cannot: that the transaction type
 * keys exist in the company and that the permissions of the stages exist (X-AC-03). A typo would
 * otherwise leave entries that nobody can approve. Keys of the system (`system.` prefix) are fixed by
 * the product and need no transaction type.
 */
final class UpdateApprovalPolicy
{
    public function __construct(
        private readonly ApprovalPolicies $policies,
        private readonly TransactionTypeKeys $transactionTypes,
        private readonly Permissions $permissions,
    ) {}

    /**
     * @param  list<ApprovalRule>  $rules
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $companyId, bool $separationOfDuties, array $rules, int $expectedVersion, int $actorId): ApprovalPolicyData
    {
        $errors = [];
        $custom = [];

        foreach ($rules as $index => $rule) {
            if ($rule->transactionTypeKey !== null && ! str_starts_with($rule->transactionTypeKey, UpdateLedgerPolicy::SYSTEM_PREFIX)) {
                $custom[$index] = $rule->transactionTypeKey;
            }

            foreach ($rule->stages as $stage => $permission) {
                if (! $this->permissions->exists($permission)) {
                    $errors["rules.{$index}.stages.{$stage}"] = [__('entries.policy_permission_unknown', ['permission' => $permission])];
                }
            }
        }

        foreach ($this->transactionTypes->missing($companyId, array_values(array_unique($custom))) as $key) {
            foreach (array_keys($custom, $key, true) as $index) {
                $errors["rules.{$index}.transaction_type_key"] = [__('entries.policy_key_unknown', ['key' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->policies->change($companyId, $separationOfDuties, $rules, $expectedVersion, $actorId);
    }
}
