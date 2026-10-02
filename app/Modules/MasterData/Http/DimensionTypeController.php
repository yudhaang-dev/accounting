<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\CreateDimensionType;
use App\Modules\MasterData\Application\SetDimensionStatus;
use App\Modules\MasterData\Application\UpdateDimensionType;
use App\Modules\MasterData\Domain\DimensionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DimensionTypeController
{
    /**
     * Dimension types of the company, ordered by key. Filter: `filter[status]`. `per_page` defaults
     * to 50, at most 200.
     */
    public function index(ListDimensionTypesRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('filter.status');

        $types = DimensionType::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('key')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return DimensionTypeResource::collection($types);
    }

    public function show(string $companyId, string $id): DimensionTypeResource
    {
        return new DimensionTypeResource(DimensionType::query()->findOrFail($id));
    }

    /**
     * Adds a dimension type. The key is stable and cannot change afterwards.
     */
    public function store(CreateDimensionTypeRequest $request, CreateDimensionType $createType): JsonResponse
    {
        /** @var array{key: string, label: string, is_hierarchical?: bool, leaf_only?: bool} $data */
        $data = $request->validated();

        return (new DimensionTypeResource($createType($data)))->response()->setStatusCode(201);
    }

    /**
     * Changes label, hierarchy flag or leaf-only rule. `lock_version` is required; a type cannot stop
     * being hierarchical while values have a parent.
     */
    public function update(UpdateDimensionTypeRequest $request, UpdateDimensionType $updateType, string $companyId, string $id): DimensionTypeResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new DimensionTypeResource($updateType($id, $data, $expected));
    }

    /**
     * Deactivates the type: its values can no longer be used in new transactions.
     */
    public function deactivate(SetDimensionStatus $status, string $companyId, string $id): DimensionTypeResource
    {
        return new DimensionTypeResource($status->deactivateType($id));
    }

    /**
     * Reactivates the type.
     */
    public function reactivate(SetDimensionStatus $status, string $companyId, string $id): DimensionTypeResource
    {
        return new DimensionTypeResource($status->reactivateType($id));
    }
}
