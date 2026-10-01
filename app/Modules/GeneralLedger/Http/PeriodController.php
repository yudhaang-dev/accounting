<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Application\SetPeriodStatus;
use App\Modules\GeneralLedger\Domain\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PeriodController
{
    /**
     * Periods in date order. Filters: `filter[fiscal_year_id]`, `filter[status]` and `filter[date]`
     * (the period that contains the date). `per_page` defaults to 50, at most 200.
     */
    public function index(ListPeriodsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);

        $periods = Period::query()
            ->when(isset($filters['fiscal_year_id']), fn (Builder $query) => $query->where('fiscal_year_id', $filters['fiscal_year_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['date']), fn (Builder $query) => $query->where('start_date', '<=', $filters['date'])->where('end_date', '>=', $filters['date']))
            ->orderBy('start_date')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return PeriodResource::collection($periods);
    }

    public function show(string $companyId, string $id): PeriodResource
    {
        return new PeriodResource(Period::query()->findOrFail($id));
    }

    /**
     * Closes an open period: it no longer accepts postings.
     */
    public function close(SetPeriodStatus $status, string $companyId, string $id): PeriodResource
    {
        return new PeriodResource($status->close($id));
    }

    /**
     * Reopens a closed period. A `reason` is required and is audited with the actor.
     */
    public function reopen(ReopenPeriodRequest $request, SetPeriodStatus $status, string $companyId, string $id): PeriodResource
    {
        return new PeriodResource($status->reopen($id, $request->validated('reason')));
    }
}
