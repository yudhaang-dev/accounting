<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Movement of one account in one dimension set over a date range, in the functional currency. Amounts
 * are decimal strings.
 */
final readonly class AccountMovementRow
{
    public function __construct(
        public string $accountId,
        public string $dimensionSetId,
        public string $debit,
        public string $credit,
    ) {}
}
