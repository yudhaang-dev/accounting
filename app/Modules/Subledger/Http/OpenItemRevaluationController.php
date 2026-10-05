<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\RevalueOpenItems;
use App\Support\Access\ActorPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenItemRevaluationController
{
    /**
     * Revalues the foreign currency open items as of the last day of the period, at the closing rate of the
     * rate type named by the ledger policy (SL-FR-07). The difference is posted as a `system.fx_revaluation`
     * journal to the control accounts and the unrealized gain or loss accounts; with auto-reversal in the
     * policy, its reversal is dated the first day of the next period.
     *
     * Answers 201 with the run and the adjustments, or 200 with `id: null` when nothing needed adjusting.
     * Running again only posts what changed since the last run. Answers 422 `MAPPING_REQUIRED` when the
     * policy has no rate type or a role is not mapped, 422 `EXCHANGE_RATE_NOT_FOUND` when a currency has no
     * closing rate, and 422 when an allocation is dated after the period.
     *
     * @response array{data: array{id: string|null, period_id: string, journal_id: string|null, reversal_journal_id: string|null, adjustments: list<array{open_item_id: string, currency: string, remaining_amount: string, carried: string, revalued: string, adjustment: string}>}}
     */
    public function store(Request $request, RevalueOpenItems $revalue, ActorPermissions $permissions, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $revalue($companyId, $id, $user->id, $permissions->current());

        return response()->json(['data' => $result], $result['id'] === null ? 200 : 201);
    }
}
