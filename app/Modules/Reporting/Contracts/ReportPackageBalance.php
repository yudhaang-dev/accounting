<?php

namespace App\Modules\Reporting\Contracts;

/**
 * One account of a report package: what the ledger held before the range, what moved in it and what it held
 * at the cutoff, all in the functional currency as decimal strings (P-08).
 */
final readonly class ReportPackageBalance
{
    public function __construct(
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
