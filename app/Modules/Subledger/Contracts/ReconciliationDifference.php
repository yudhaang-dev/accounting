<?php

namespace App\Modules\Subledger\Contracts;

/**
 * An account whose ledger balance differs from what the subledger holds for it, and that nobody has waived (SL-FR-09).
 */
final readonly class ReconciliationDifference
{
    /**
     * @param  string  $kind  `control` or `cash`
     * @param  string  $difference  ledger less subledger, a decimal string
     */
    public function __construct(
        public string $kind,
        public string $accountId,
        public string $accountCode,
        public string $currency,
        public string $difference,
    ) {}
}
