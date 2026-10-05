<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\ManageAllocations;
use App\Modules\Subledger\Domain\Allocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AllocationController
{
    /**
     * Allocations, newest first. Filters: `filter[open_item_id]` (on either side), `filter[document_id]`
     * and `filter[status]`. `per_page` defaults to 50, at most 200.
     */
    public function index(ListAllocationsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $allocations = Allocation::query()
            ->when(isset($filter['open_item_id']), fn ($query) => $query->where(fn ($either) => $either->where('debit_item_id', $filter['open_item_id'])->orWhere('credit_item_id', $filter['open_item_id'])))
            ->when(isset($filter['document_id']), fn ($query) => $query->where('document_id', $filter['document_id']))
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->orderByDesc('allocation_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return AllocationResource::collection($allocations);
    }

    public function show(string $companyId, string $id): AllocationResource
    {
        return new AllocationResource(Allocation::query()->findOrFail($id));
    }

    /**
     * Settles an amount of two open items of the same party, control account and currency against each
     * other, one on the debit side and one on the credit side, for example an advance against an invoice.
     * Makes no journal.
     */
    public function store(AllocateRequest $request, ManageAllocations $allocations, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{open_item_id: string, counterpart_open_item_id: string, amount: string, allocation_date: string} $data */
        $data = $request->validated();

        $allocation = $allocations->allocate($data['open_item_id'], $data['counterpart_open_item_id'], $data['amount'], $data['allocation_date'], $user->id);

        return (new AllocationResource($allocation->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Cancels an active allocation with a reason. The open items it settled open again by its amount.
     */
    public function cancel(CancelAllocationRequest $request, ManageAllocations $allocations, string $companyId, string $id): AllocationResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AllocationResource($allocations->cancel($id, $request->string('reason')->toString(), now()->toDateString(), $user->id));
    }
}
