<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Account metadata for other modules and the application layer (GL-FR-01).
 */
interface AccountDirectory
{
    /** The account, or null when it does not exist in that company. */
    public function find(string $companyId, string $accountId): ?AccountData;

    /**
     * Classes that have no active leaf account yet; empty when the chart can take postings of every kind.
     *
     * @return list<AccountClass>
     */
    public function missingClasses(string $companyId): array;
}
