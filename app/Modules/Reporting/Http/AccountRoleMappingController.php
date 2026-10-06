<?php

namespace App\Modules\Reporting\Http;

use App\Models\User;
use App\Modules\Reporting\Application\CashEquivalents;
use Illuminate\Http\JsonResponse;

class AccountRoleMappingController
{
    /**
     * The roles that Reporting owns: `cash_equivalents`, one or more asset accounts that the cash flow
     * counts as cash (RP-FR-03). `account_ids` is empty when the role is not mapped, and `problems`
     * says, per account, why one of them no longer qualifies (inactive, for example), so an
     * incomplete mapping is visible. `lock_version` is the version of the mapping (0 when none).
     *
     * @response array{data: list<array{key: string, account_ids: list<string>, effective_date: string|null, problems: array<string, list<string>>, lock_version: int}>}
     */
    public function index(CashEquivalents $cash, string $companyId): JsonResponse
    {
        $mapping = $cash->current($companyId);

        return response()->json(['data' => [[
            'key' => CashEquivalents::ROLE,
            'account_ids' => $mapping['account_ids'] ?? [],
            'effective_date' => $mapping['effective_date'] ?? null,
            'problems' => $cash->problems($companyId, $mapping['account_ids'] ?? []),
            'lock_version' => $mapping['version'] ?? 0,
        ]]]);
    }

    /**
     * Maps `cash_equivalents` to the given accounts as a new version; the change is audited. Every
     * account must be an active asset leaf of the company. `lock_version` is the one you read (0
     * when the role is not mapped yet) and `effective_date` defaults to today, or to all dates for
     * the first mapping.
     *
     * @response array{data: array{key: string, account_ids: list<string>, effective_date: string, lock_version: int}}
     */
    public function update(UpdateAccountRoleMappingRequest $request, CashEquivalents $cash, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, account_ids: list<string>, effective_date?: string|null} $data */
        $data = $request->validated();

        $mapping = $cash->change($companyId, $data['account_ids'], (int) $data['lock_version'], $user->id, $data['effective_date'] ?? null);

        return response()->json(['data' => [
            'key' => CashEquivalents::ROLE,
            'account_ids' => $mapping['account_ids'],
            'effective_date' => $mapping['effective_date'],
            'lock_version' => $mapping['version'],
        ]]);
    }
}
