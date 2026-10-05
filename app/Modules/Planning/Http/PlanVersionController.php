<?php

namespace App\Modules\Planning\Http;

use App\Models\User;
use App\Modules\Planning\Application\ManagePlanVersions;
use App\Modules\Planning\Domain\PlanVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanVersionController
{
    /**
     * Versions of the plans of the company, newest first. Filters: `filter[plan_id]`,
     * `filter[status]` (`draft`, `published` or `archived`). `per_page` defaults to 50, at most 200.
     */
    public function index(ListPlanVersionsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $versions = PlanVersion::query()
            ->when(isset($filter['plan_id']), fn ($query) => $query->where('plan_id', $filter['plan_id']))
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return PlanVersionResource::collection($versions);
    }

    public function show(string $companyId, string $id): PlanVersionResource
    {
        return new PlanVersionResource(PlanVersion::query()->findOrFail($id));
    }

    /**
     * Adds an empty draft version to the plan. The name is unique within the plan.
     */
    public function store(CreatePlanVersionRequest $request, ManagePlanVersions $versions, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new PlanVersionResource($versions->create($id, $request->validated('name'), $user->id)))->response()->setStatusCode(201);
    }

    /**
     * Renames a draft. A version that is published or archived cannot change (409
     * `INVALID_STATE_TRANSITION`); revise it instead. `lock_version` is required.
     */
    public function update(UpdatePlanVersionRequest $request, ManagePlanVersions $versions, string $companyId, string $id): PlanVersionResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        return new PlanVersionResource($versions->rename($id, $data['name'], (int) $data['lock_version'], $user->id));
    }

    /**
     * Revises a published version: a new draft of the same plan copies its lines and names it as its
     * origin, while the published version keeps its values. Any other status gives 409
     * `INVALID_STATE_TRANSITION`.
     */
    public function revise(RevisePlanVersionRequest $request, ManagePlanVersions $versions, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new PlanVersionResource($versions->revise($id, $request->validated('name'), $user->id)))->response()->setStatusCode(201);
    }
}
