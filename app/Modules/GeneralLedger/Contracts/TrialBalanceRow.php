<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One account of a trial balance (RP-FR-05). Amounts are decimal strings in the functional
 * currency; the opening and closing balance are shown on the side they belong to, the other is zero.
 */
final readonly class TrialBalanceRow
{
    public function __construct(
        public string $accountId,
        public string $code,
        public string $name,
        public AccountClass $class,
        public string $openingDebit,
        public string $openingCredit,
        public string $debit,
        public string $credit,
        public string $closingDebit,
        public string $closingCredit,
    ) {}
}
