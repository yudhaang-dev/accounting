<?php

namespace App\Modules\Planning\Http;

use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Planning\Application\ManagePlans;
use App\Modules\Planning\Domain\Plan;
use App\Support\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController
{
    /**
     * Plans of the company, ordered by name. Filter: `filter[fiscal_year_id]` keeps the plans whose
     * period overlaps that fiscal year. `per_page` defaults to 50, at most 200.
     */
    public function index(ListPlansRequest $request, PeriodDirectory $periods, CompanyContext $company): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];
        $year = isset($filter['fiscal_year_id']) ? $periods->fiscalYear($company->require(), $filter['fiscal_year_id']) : null;

        $plans = Plan::query()
            ->when(isset($filter['fiscal_year_id']), fn ($query) => $year === null
                ? $query->whereRaw('false')
                : $query->where('starts_on', '<=', $year->endDate)->where('ends_on', '>=', $year->startDate))
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
     * Adds a plan: a title (`name`), an `objective`, its own period (`starts_on`..`ends_on`, which may
     * span fiscal years) and optionally the dimension value it is measured against
     * (`scope_dimension_value_id`; none means the whole company). The name is unique in the company.
     * The versions that hold its amounts are added with `POST /plans/{id}/versions`.
     */
    public function store(CreatePlanRequest $request, ManagePlans $plans): JsonResponse
    {
        return (new PlanResource($plans->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Changes the name, objective or description. The period and the scope change only until a version
     * has been published, and a period cannot leave out a period that already has lines.
     * `lock_version` is required.
     */
    public function update(UpdatePlanRequest $request, ManagePlans $plans, string $companyId, string $id): PlanResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new PlanResource($plans->update($id, $data, $expected));
    }
}
