<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Application\ManagePlans;
use App\Modules\Planning\Domain\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController
{
    /**
     * Plans of the company, ordered by name. Filter: `filter[fiscal_year_id]`. `per_page` defaults
     * to 50, at most 200.
     */
    public function index(ListPlansRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $plans = Plan::query()
            ->when(isset($filter['fiscal_year_id']), fn ($query) => $query->where('fiscal_year_id', $filter['fiscal_year_id']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return PlanResource::collection($plans);
    }

    public function show(string $companyId, string $id): PlanResource
    {
        return new PlanResource(Plan::query()->findOrFail($id));
    }

    /**
     * Adds a plan for a fiscal year. The name is unique within the fiscal year. The versions that hold
     * its amounts are added with `POST /plans/{id}/versions`.
     */
    public function store(CreatePlanRequest $request, ManagePlans $plans): JsonResponse
    {
        return (new PlanResource($plans->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Changes the name or the description. The fiscal year cannot change. `lock_version` is required.
     */
    public function update(UpdatePlanRequest $request, ManagePlans $plans, string $companyId, string $id): PlanResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new PlanResource($plans->update($id, $data, $expected));
    }
}
