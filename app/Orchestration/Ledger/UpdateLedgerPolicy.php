<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\LedgerPolicyData;
use App\Modules\IdentityAccess\Contracts\Permissions;
use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
use App\Platform\Api\ApiException;
use Illuminate\Validation\ValidationException;

/**
 * Changes the ledger policy after checking what the ledger cannot: that the transaction type keys
 * exist in the company and that the permission exists (X-AC-03). A typo would otherwise lock every
 * poster out of soft-closed periods without telling anyone. Keys of the system (`system.` prefix)
 * are fixed by the product and need no transaction type.
 */
final class UpdateLedgerPolicy
{
    public const SYSTEM_PREFIX = 'system.';

    public function __construct(
        private readonly LedgerPolicies $policies,
        private readonly TransactionTypeKeys $transactionTypes,
        private readonly Permissions $permissions,
    ) {}

    /**
     * @param  list<string>  $softClosedTransactionTypeKeys
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $companyId, array $softClosedTransactionTypeKeys, ?string $softClosedPermission, ?string $sourceOverridePermission, int $expectedVersion, int $actorId): LedgerPolicyData
    {
        $errors = [];

        $custom = array_values(array_filter($softClosedTransactionTypeKeys, fn (string $key): bool => ! str_starts_with($key, self::SYSTEM_PREFIX)));

        foreach ($this->transactionTypes->missing($companyId, $custom) as $key) {
            $index = array_search($key, $softClosedTransactionTypeKeys, true);
            $errors["soft_closed_transaction_type_keys.{$index}"] = [__('ledger.policy_key_unknown', ['key' => $key])];
        }

        if ($softClosedPermission !== null && ! $this->permissions->exists($softClosedPermission)) {
            $errors['soft_closed_permission'] = [__('ledger.policy_permission_unknown', ['permission' => $softClosedPermission])];
        }

        if ($sourceOverridePermission !== null && ! $this->permissions->exists($sourceOverridePermission)) {
            $errors['source_override_permission'] = [__('ledger.policy_permission_unknown', ['permission' => $sourceOverridePermission])];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->policies->change($companyId, $softClosedTransactionTypeKeys, $softClosedPermission, $sourceOverridePermission, $expectedVersion, $actorId);
    }
}
