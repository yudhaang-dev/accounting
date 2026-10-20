<?php

namespace App\Support\Currency;

/**
 * What a company needs in place before it may use a foreign currency, so MasterData can refuse to
 * activate one without knowing the ledger (GL-FR-10): the exchange difference roles must be mapped.
 * GeneralLedger provides the implementation.
 */
interface ForeignCurrencyRequirements
{
    /**
     * Fails with ApiException MAPPING_REQUIRED naming each role that is not mapped.
     */
    public function assertMet(string $companyId): void;
}
