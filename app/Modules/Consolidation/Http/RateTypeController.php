<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Application\ManageRateTypes;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\RateType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RateTypeController
{
    /** The rate types of the group, by code, inactive ones included. */
    public function index(string $group): AnonymousResourceCollection
    {
        return RateTypeResource::collection(RateType::query()->where('group_id', Group::query()->findOrFail($group)->id)->orderBy('code')->get());
    }

    public function store(CreateRateTypeRequest $request, ManageRateTypes $types, string $group): JsonResponse
    {
        $type = $types->create($group, $request->string('code')->toString(), $request->string('name')->toString());

        return (new RateTypeResource($type))->response()->setStatusCode(201);
    }

    /** Renames or deactivates a type; its code never changes. A type the current translation policy names stays active. */
    public function update(UpdateRateTypeRequest $request, ManageRateTypes $types, string $group, string $id): RateTypeResource
    {
        return new RateTypeResource($types->update($group, $id, $request->safe()->only(['name', 'is_active']), $request->integer('lock_version')));
    }
}
