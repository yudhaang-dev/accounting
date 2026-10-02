<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The settings of a new ledger policy version; see `LedgerPolicyData` for what each one means.
 */
final readonly class LedgerPolicyChange
{
    /**
     * @param  list<string>  $softClosedTransactionTypeKeys
     */
    public function __construct(
        public array $softClosedTransactionTypeKeys,
        public ?string $softClosedPermission = null,
        public ?string $sourceOverridePermission = null,
        public string $fxRoundingTolerance = '0',
        public ?string $revaluationRateTypeKey = null,
        public bool $revaluationAutoReverse = false,
    ) {}
}
