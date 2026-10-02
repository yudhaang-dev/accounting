<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The current ledger policy of a company (GL-FR-04, P-04). Version 0 means it never set one, and
 * then a soft-closed period accepts no posting at all.
 */
final readonly class LedgerPolicyData
{
    /**
     * @param  list<string>  $softClosedTransactionTypeKeys  keys that may post into a soft-closed period
     * @param  string|null  $softClosedPermission  permission the poster must hold as well; null when the key suffices
     * @param  string|null  $sourceOverridePermission  permission that lets a poster post to an account from a source it does not accept (GL-FR-07); null when the policy allows no override
     * @param  string  $fxRoundingTolerance  largest rounding difference, in the functional currency, that a foreign currency journal may carry and have booked to `fx_rounding` (GL-FR-05); `0` allows none
     */
    public function __construct(
        public int $version,
        public array $softClosedTransactionTypeKeys,
        public ?string $softClosedPermission,
        public ?string $sourceOverridePermission = null,
        public string $fxRoundingTolerance = '0',
    ) {}

    public static function none(): self
    {
        return new self(0, [], null, null, '0');
    }

    /**
     * Whether an actor holding these permissions may post to an account from a source it does not
     * accept (GL-FR-07). The default policy allows nobody.
     *
     * @param  list<string>  $actorPermissions
     */
    public function allowsSourceOverride(array $actorPermissions): bool
    {
        return $this->sourceOverridePermission !== null && in_array($this->sourceOverridePermission, $actorPermissions, true);
    }

    /**
     * Whether a posting with this transaction type key, by an actor holding these permissions, may
     * enter a soft-closed period (GL-AC-04).
     *
     * @param  list<string>  $actorPermissions
     */
    public function allowsSoftClosedPosting(?string $transactionTypeKey, array $actorPermissions): bool
    {
        if ($transactionTypeKey === null || ! in_array($transactionTypeKey, $this->softClosedTransactionTypeKeys, true)) {
            return false;
        }

        return $this->softClosedPermission === null || in_array($this->softClosedPermission, $actorPermissions, true);
    }
}
