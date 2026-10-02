<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The balance of one account in one transaction currency (GL-FR-08). `debit` and `credit` are in
 * that currency, `functionalDebit` and `functionalCredit` are the same movements as recorded in the
 * functional currency; all are decimal strings.
 */
final readonly class CurrencyBalanceRow
{
    public function __construct(
        public string $accountId,
        public string $code,
        public string $name,
        public string $currency,
        public string $debit,
        public string $credit,
        public string $functionalDebit,
        public string $functionalCredit,
    ) {}
}
