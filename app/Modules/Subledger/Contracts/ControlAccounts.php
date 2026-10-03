<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Resolves the receivable or payable control account of a party through its posting profile
 * (SL-FR-02). A party without a profile uses the default one.
 */
interface ControlAccounts
{
    /**
     * The mapping in force on `$asOf` (the latest when null), checked to still be a valid target.
     * With a party, its posting profile is used; without one, the default profile.
     *
     * Fails with ApiException MAPPING_REQUIRED, saying which role and why.
     *
     * @param  string|null  $asOf  `YYYY-MM-DD`
     */
    public function require(string $companyId, ControlRole $role, ?string $partyId = null, ?string $asOf = null): ControlAccount;
}
