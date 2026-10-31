<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Looks up parties for other modules (SL-FR-01). Read only.
 */
interface PartyDirectory
{
    /** The party, or null when it does not exist in that company. */
    public function find(string $companyId, string $partyId): ?PartyData;

    /**
     * The keys among `$roleKeys` that are no party role of the company or are inactive (SL-FR-01).
     *
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    public function unusableRoles(string $companyId, array $roleKeys): array;
}
