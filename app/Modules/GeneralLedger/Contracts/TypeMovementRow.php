<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Movement of one account in journals of one transaction type key over a date range, in the
 * functional currency; the key is null for journals that carry none. Amounts are decimal strings.
 */
final readonly class TypeMovementRow
{
    public function __construct(
        public string $accountId,
        public ?string $transactionTypeKey,
        public string $debit,
        public string $credit,
    ) {}
}
