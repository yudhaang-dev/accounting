<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\CreateRateType;
use App\Modules\MasterData\Application\SetRateTypeStatus;
use App\Modules\MasterData\Application\UpdateRateType;
use App\Modules\MasterData\Domain\RateType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RateTypeController
{
    /**
     * Rate types of the company, ordered by key. Filter: `filter[status]`. `per_page` defaults to 50,
     * at most 200.
     */
    public function index(ListDimensionTypesRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('filter.status');

        $types = RateType::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('key')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return RateTypeResource::collection($types);
    }

    public function show(string $companyId, string $id): RateTypeResource
    {
        return new RateTypeResource(RateType::query()->findOrFail($id));
    }

    /**
     * Adds a rate type. The key is stable and cannot change afterwards.
     */
    public function store(CreateRateTypeRequest $request, CreateRateType $createType): JsonResponse
    {
        /** @var array{key: string, label: string, max_age_days?: int|null} $data */
        $data = $request->validated();

        return (new RateTypeResource($createType($data)))->response()->setStatusCode(201);
    }

    /**
     * Changes the label or the age limit. `lock_version` is required.
     */
    public function update(UpdateRateTypeRequest $request, UpdateRateType $updateType, string $companyId, string $id): RateTypeResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new RateTypeResource($updateType($id, $data, $expected));
    }

    /**
     * Deactivates the type: no new rates can be recorded for it; existing rates stay readable.
     */
    public function deactivate(SetRateTypeStatus $status, string $companyId, string $id): RateTypeResource
    {
        return new RateTypeResource($status->deactivate($id));
    }

    /**
     * Reactivates the type.
     */
    public function reactivate(SetRateTypeStatus $status, string $companyId, string $id): RateTypeResource
    {
        return new RateTypeResource($status->reactivate($id));
    }
}
