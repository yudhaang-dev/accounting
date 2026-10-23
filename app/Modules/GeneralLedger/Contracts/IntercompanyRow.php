<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * What one account holds against one other group company (GL-FR-15): the lines that name that company as
 * their counterparty, in the functional currency as decimal strings. The opening and closing balance are on the
 * side they belong to, the other is zero.
 */
final readonly class IntercompanyRow
{
    public function __construct(
        public string $counterpartyCompanyId,
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
