<?php

namespace App\Support\Currency;

/**
 * Tells whether a currency of a company has been used by a transaction, so MasterData can refuse to
 * deactivate it without knowing the ledger (MD-FR-06). GeneralLedger provides the implementation.
 */
interface CurrencyUsage
{
    public function isUsed(string $companyId, string $currency): bool;
}
