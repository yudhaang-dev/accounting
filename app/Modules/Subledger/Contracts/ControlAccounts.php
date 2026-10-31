<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Resolves the receivable or payable control account of a party through its posting profile
 * (SL-FR-02). A party without a profile uses the default one; a route that names a profile uses that one.
 */
interface ControlAccounts
{
    /**
     * The mapping in force on `$asOf` (the latest when null), checked to still be a valid target.
     * A profile named by the caller is used; else the profile of the party; else the default profile.
     *
     * Fails with ApiException MAPPING_REQUIRED, saying which role and why.
     *
     * @param  string|null  $asOf  `YYYY-MM-DD`
     */
    public function require(string $companyId, ControlRole $role, ?string $partyId = null, ?string $asOf = null, ?string $postingProfileId = null): ControlAccount;

    /**
     * Why a route cannot use the profile for the role, in the language of the request; empty when it can: the
     * profile does not exist in the company or does not map the role.
     *
     * @return list<string>
     */
    public function profileProblems(string $companyId, string $postingProfileId, ControlRole $role): array;
}
