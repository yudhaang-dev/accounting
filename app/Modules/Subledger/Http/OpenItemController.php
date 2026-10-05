<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\OpenItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpenItemController
{
    /**
     * Open items, oldest due date first. Filters: `filter[party_id]`, `filter[control_role]`,
     * `filter[kind]`, `filter[status]` and `filter[due_to]` (due on or before the date). `per_page`
     * defaults to 50, at most 200.
     */
    public function index(ListOpenItemsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $items = OpenItem::query()
            ->when(isset($filter['party_id']), fn ($query) => $query->where('party_id', $filter['party_id']))
            ->when(isset($filter['control_role']), fn ($query) => $query->where('control_role', $filter['control_role']))
            ->when(isset($filter['kind']), fn ($query) => $query->where('kind', $filter['kind']))
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->when(isset($filter['due_to']), fn ($query) => $query->whereDate('due_date', '<=', $filter['due_to']))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return OpenItemResource::collection($items);
    }

    public function show(string $companyId, string $id): OpenItemResource
    {
        return new OpenItemResource(OpenItem::query()->findOrFail($id));
    }
}
