<?php

namespace App\Orchestration\Ledger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\FiscalYearClose;
use App\Modules\GeneralLedger\Contracts\FiscalYearClosing;
use App\Modules\GeneralLedger\Contracts\FiscalYearReopen;
use App\Orchestration\Ledger\CloseFiscalYear;
use App\Support\Access\ActorPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearClosingController
{
    /**
     * Closes the fiscal year (GL-FR-12): one `system.closing` journal dated its last day brings every
     * income and expense account to zero per dimension set against the `retained_earnings` account.
     * Balance sheet accounts carry on into the next year. Answers 201 with the closing, or 200 when
     * the year was closed already and nothing was posted. Answers 409 `INVALID_STATE_TRANSITION` while
     * entries dated up to the end of the year are unfinished, 422 `MAPPING_REQUIRED` without a
     * `retained_earnings` mapping, 422 `UNBALANCED_ENTRY` when the ledger does not balance and 422
     * `PERIOD_NOT_OPEN` when the last period does not accept the closing journal.
     */
    public function store(Request $request, CloseFiscalYear $close, ActorPermissions $permissions, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $run = $close(new FiscalYearClose($companyId, $id, $user->id, $permissions->current()));

        return (new ClosingRunResource($run))->response()->setStatusCode($run->replayed ? 200 : 201);
    }

    /**
     * Reopens a closed fiscal year (GL-FR-14): the closing journal is reversed on the last day of the
     * year, and the year can be closed again with a new closing. Needs a `reason`, which is audited.
     * Answers 409 `INVALID_STATE_TRANSITION` when the year is not closed and 422 `PERIOD_NOT_OPEN` when
     * the period of the last day does not accept the reversal: reopen that period first. While a year is
     * closed, the ledger accepts no posting into it.
     */
    public function reopen(ReopenFiscalYearRequest $request, FiscalYearClosing $closing, ActorPermissions $permissions, string $companyId, string $id): ClosingRunResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ClosingRunResource($closing->reopen(new FiscalYearReopen($companyId, $id, $request->validated('reason'), $user->id, $permissions->current())));
    }
}
