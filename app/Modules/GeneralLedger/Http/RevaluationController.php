<?php

namespace App\Modules\GeneralLedger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Application\RunRevaluation;
use App\Support\Access\ActorPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevaluationController
{
    /**
     * Revalues the foreign currency balances of the accounts marked for revaluation, as of the last day
     * of the period, at the closing rate of the rate type named by the ledger policy (GL-FR-09). Control
     * accounts of a subledger are left out. The difference is posted as a `system.fx_revaluation`
     * journal; with auto-reversal in the policy, its reversal is dated the first day of the next period.
     *
     * Answers 201 with the run and the adjustments, or 200 with `id: null` when nothing needed adjusting.
     * Running again only posts what changed since the last run. Answers 422 `MAPPING_REQUIRED` when the
     * policy has no rate type or the unrealized gain or loss role is not mapped, and 422
     * `EXCHANGE_RATE_NOT_FOUND` when a currency has no closing rate.
     */
    public function store(Request $request, RunRevaluation $revalue, ActorPermissions $permissions, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $revalue($companyId, $id, $user->id, $permissions->current());

        return response()->json(['data' => [
            'id' => $result->runId,
            'period_id' => $result->periodId,
            'journal_id' => $result->journalId,
            'reversal_journal_id' => $result->reversalJournalId,
            'adjustments' => $result->adjustments,
        ]], $result->runId === null ? 200 : 201);
    }
}
