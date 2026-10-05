<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Looks up cash and bank accounts for other modules (SL-FR-03). Read only.
 */
interface CashAccountDirectory
{
    /** The cash account, or null when it does not exist in that company. */
    public function find(string $companyId, string $cashAccountId): ?CashAccountData;
}
