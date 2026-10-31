<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\PlanEvaluation;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;

class PlanEvaluationController
{
    /**
     * Measures an activity plan against the ledger (RP-FR-11, RP-FR-12). For every period of the plan
     * and every income or expense account that has an actual or a plan, the `actual` posted in the
     * scope of the plan (its dimension value and the values below it; none means the whole company)
     * beside the `initial` estimate (the first published version) and the `revision` (the last
     * published version, or `revision_version_id`). Amounts are positive in the direction of the normal
     * balance of the account. `variances` give realised minus planned, the percentage of the plan and
     * whether the difference is favorable; a version that does not exist gives `null`, never zero.
     * `periods` give the result (income minus expense) of each period and its difference from each
     * version, where positive is favorable, with the running total in `cumulative_variance`. For a
     * user limited to some dimension values only the lines inside that scope count in `actual` and
     * `restricted` is true (IA-FR-03).
     *
     * @response array{data: array{plan: array{id: string, name: string, objective: string|null, starts_on: string, ends_on: string, scope_dimension_value_id: string|null}, functional_currency: string, restricted: bool, versions: array{initial: array{id: string, name: string}|null, revision: array{id: string, name: string}|null}, available_versions: list<array{id: string, name: string}>, periods: list<array{id: string, name: string, start_date: string, end_date: string, result: array{actual: string, initial: string|null, revision: string|null}, variance: array{initial: string|null, revision: string|null}, cumulative_variance: array{initial: string|null, revision: string|null}}>, rows: list<array{account_id: string, code: string, name: string, class: string, normal_balance: string, cells: list<array<string, mixed>>, total: array<string, mixed>}>}}
     */
    public function show(PlanEvaluationRequest $request, PlanEvaluation $evaluation, DataScopeContext $scope, string $companyId): JsonResponse
    {
        /** @var array{plan_id: string, revision_version_id?: string|null} $params */
        $params = $request->validated();

        return new JsonResponse(['data' => $evaluation($companyId, $params['plan_id'], $params['revision_version_id'] ?? null, $scope->current())]);
    }
}
