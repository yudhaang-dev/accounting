<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Application\ManagePlanningPolicy;
use Illuminate\Http\JsonResponse;

class PlanningPolicyController
{
    /**
     * The publisher rule and the default comparison version of each fiscal year. Without a policy the
     * rule is off, no year has a default and `lock_version` is 0.
     *
     * @response array{data: array{require_distinct_publisher: bool, default_versions: list<array{fiscal_year_id: string, plan_version_id: string}>, lock_version: int}}
     */
    public function show(ManagePlanningPolicy $policy): JsonResponse
    {
        return new JsonResponse(['data' => $policy->current()]);
    }

    /**
     * Sets whether the publisher must differ from whoever changed the version last, and the default
     * comparison versions: only a published version of a plan of the same fiscal year qualifies (PL-FR-04).
     * `lock_version` is required, 0 for a company that has no policy yet.
     *
     * @response array{data: array{require_distinct_publisher: bool, default_versions: list<array{fiscal_year_id: string, plan_version_id: string}>, lock_version: int}}
     */
    public function update(UpdatePlanningPolicyRequest $request, ManagePlanningPolicy $policy): JsonResponse
    {
        /** @var array{lock_version: int, require_distinct_publisher: bool, default_versions?: list<array{fiscal_year_id: string, plan_version_id: string|null}>} $data */
        $data = $request->validated();

        return new JsonResponse(['data' => $policy->update($data['require_distinct_publisher'], $data['default_versions'] ?? [], $data['lock_version'])]);
    }
}
