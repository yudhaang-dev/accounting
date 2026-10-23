<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\PartyBalances;
use Illuminate\Http\JsonResponse;

class PartyBalancesController
{
    /**
     * Receivable and payable balance of each party on `as_of` (a past date is allowed), in the functional
     * currency. The figures are the totals of the aging report, so they agree with it. Parties with nothing
     * outstanding are not listed.
     *
     * @response array{data: array{as_of: string, functional_currency: string, parties: list<array{party_id: string, receivable: string, payable: string}>}}
     */
    public function show(PartyBalancesRequest $request, PartyBalances $balances, string $companyId): JsonResponse
    {
        return new JsonResponse(['data' => $balances($companyId, (string) $request->validated('as_of'))]);
    }
}
