<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\AccountReconciliations;
use App\Modules\Subledger\Domain\ReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReconciliationController
{
    /**
     * Reconciliations kept, newest date first. Filters: `filter[as_of]`, `filter[account_id]` and
     * `filter[status]` (`balanced`, `difference`, `waived`). `per_page` defaults to 50, at most 200.
     */
    public function index(ListReconciliationsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $runs = ReconciliationRun::query()
            ->when(isset($filter['as_of']), fn ($query) => $query->whereDate('as_of', $filter['as_of']))
            ->when(isset($filter['account_id']), fn ($query) => $query->where('account_id', $filter['account_id']))
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->orderByDesc('as_of')
            ->orderByDesc('created_at')
            ->orderBy('account_code')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return ReconciliationResource::collection($runs);
    }

    public function show(string $companyId, string $id): ReconciliationResource
    {
        return new ReconciliationResource(ReconciliationRun::query()->findOrFail($id));
    }

    /**
     * Compares the ledger balance of every control and cash account with the total of its subledger on a
     * date. The response has every account; only differences are kept, so an account that balanced has no
     * `id`. A difference lists the journals the subledger did not post to the account.
     */
    public function store(RunReconciliationRequest $request, AccountReconciliations $reconciliations, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $runs = $reconciliations->run($companyId, (string) $request->validated('as_of'), $user->id);

        return ReconciliationResource::collection(collect($runs))->response()->setStatusCode(201);
    }

    /**
     * Waives the difference of a reconciliation with a reason, so that it no longer blocks the closing of the year.
     */
    public function waive(WaiveReconciliationRequest $request, AccountReconciliations $reconciliations, string $companyId, string $id): ReconciliationResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ReconciliationResource($reconciliations->waive($id, (string) $request->validated('reason'), $user->id));
    }
}
