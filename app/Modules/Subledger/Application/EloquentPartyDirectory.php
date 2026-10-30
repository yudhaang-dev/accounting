<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\PartyData;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\Subledger\Domain\Party;
use App\Support\Company\CompanyScope;

/**
 * Takes the company explicitly, so other modules can read parties without a request context.
 */
final class EloquentPartyDirectory implements PartyDirectory
{
    public function find(string $companyId, string $partyId): ?PartyData
    {
        $party = Party::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereKey($partyId)->with('roleAssignments.role')->first();

        return $party === null ? null : new PartyData($party->id, $party->name, $party->roleKeys(), $party->isActive(), $party->counterparty_company_id);
    }
}
