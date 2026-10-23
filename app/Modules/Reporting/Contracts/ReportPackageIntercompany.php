<?php

namespace App\Modules\Reporting\Contracts;

/**
 * What one account of the package holds against another group company (CO-FR-07, GL-FR-15): the balances of the
 * lines that name it as counterparty, in the functional currency as decimal strings. A subset of the balance of
 * the account, not an addition to it.
 */
final readonly class ReportPackageIntercompany
{
    public function __construct(
        public string $counterpartyCompanyId,
        public string $accountId,
        public string $code,
        public string $name,
        public string $class,
        public string $openingDebit,
        public string $openingCredit,
        public string $debit,
        public string $credit,
        public string $closingDebit,
        public string $closingCredit,
    ) {}
}
