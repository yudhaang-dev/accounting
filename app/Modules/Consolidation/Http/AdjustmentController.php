<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\ManageAdjustments;
use App\Modules\Consolidation\Application\RunResult;
use App\Modules\Consolidation\Domain\Adjustment;
use App\Modules\Consolidation\Domain\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentController
{
    /**
     * The adjustments recorded on a run, oldest first, whatever their status; only the approved ones are part of
     * the result of the run. Needs a read grant on the group.
     *
     * @response array{data: list<array{id: string, run_id: string, source: string, reason: string, status: string, proposed_by: int, decided_by: int|null, decided_at: string|null, rejection_reason: string|null, created_at: string|null, lines: list<array{group_account_id: string, code: string, name: string, class: string, debit: string, credit: string}>}>}
     */
    public function index(RunResult $result, string $group, string $run): JsonResponse
    {
        $run = Run::query()->where('group_id', $group)->findOrFail($run);

        return response()->json(['data' => $result->adjustments($run)]);
    }

    /**
     * Proposes an adjustment of a run on accounts of the group (CO-FR-08): a source, a reason, and balanced
     * lines of one side each in the presentation currency. It changes nothing until someone other than the
     * proposer approves it, and it is never posted to a ledger of an entity. Needs a run grant on the group.
     *
     * @response 201 array{data: array{id: string, run_id: string, source: string, reason: string, status: string, proposed_by: int, decided_by: int|null, decided_at: string|null, rejection_reason: string|null, created_at: string|null, lines: list<array{group_account_id: string, code: string, name: string, class: string, debit: string, credit: string}>}}
     */
    public function store(CreateAdjustmentRequest $request, ManageAdjustments $adjustments, RunResult $result, string $group, string $run): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $adjustment = $adjustments->propose($group, $run, $request->string('source')->toString(), $request->string('reason')->toString(), array_values(array_map(
            fn (array $line): array => ['group_account_id' => (string) $line['group_account_id'], 'debit' => (string) $line['debit'], 'credit' => (string) $line['credit']],
            $request->array('lines'),
        )), $user->id);

        return response()->json(['data' => $result->adjustment($adjustment)], 201);
    }

    /**
     * Approves a pending adjustment, which from then on is part of the result of its run. Needs the approval
     * ability on the group, and the approver cannot be who proposed it (SEGREGATION_OF_DUTIES).
     *
     * @response array{data: array{id: string, run_id: string, source: string, reason: string, status: string, proposed_by: int, decided_by: int|null, decided_at: string|null, rejection_reason: string|null, created_at: string|null, lines: list<array{group_account_id: string, code: string, name: string, class: string, debit: string, credit: string}>}}
     */
    public function approve(Request $request, ManageAdjustments $adjustments, RunResult $result, string $group, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $result->adjustment($adjustments->approve($group, $id, $user->id))]);
    }

    /**
     * Rejects a pending adjustment with a reason; it stays on record and is not part of the result. Same rules
     * as approving.
     *
     * @response array{data: array{id: string, run_id: string, source: string, reason: string, status: string, proposed_by: int, decided_by: int|null, decided_at: string|null, rejection_reason: string|null, created_at: string|null, lines: list<array{group_account_id: string, code: string, name: string, class: string, debit: string, credit: string}>}}
     */
    public function reject(RejectAdjustmentRequest $request, ManageAdjustments $adjustments, RunResult $result, string $group, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $result->adjustment($adjustments->reject($group, $id, $user->id, $request->string('reason')->toString()))]);
    }
}
