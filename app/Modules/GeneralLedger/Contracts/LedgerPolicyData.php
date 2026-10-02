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
     */
    public function __construct(
        public int $version,
        public array $softClosedTransactionTypeKeys,
        public ?string $softClosedPermission,
    ) {}

    public static function none(): self
    {
        return new self(0, [], null);
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
