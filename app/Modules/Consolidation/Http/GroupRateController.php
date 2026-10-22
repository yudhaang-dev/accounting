<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\RecordGroupRate;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupRateController
{
    /**
     * Rates of the group, newest effective date first. By default only the current version of each rate is
     * listed. Filters: `filter[rate_type_id]`, `filter[currency]`, `filter[from]` and `filter[to]` (effective
     * date), and `filter[include_superseded]=1` to list every version. `per_page` defaults to 50, at most 200.
     */
    public function index(ListGroupRatesRequest $request, string $group): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);

        $rates = GroupRate::query()
            ->where('group_id', Group::query()->findOrFail($group)->id)
            ->when(isset($filters['rate_type_id']), fn (Builder $query) => $query->where('rate_type_id', $filters['rate_type_id']))
            ->when(isset($filters['currency']), fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('effective_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('effective_date', '<=', $filters['to']))
            ->unless($filters['include_superseded'] ?? false, fn (Builder $query) => $query->whereNotExists(
                fn ($newer) => $newer->from('co_group_rates as newer')
                    ->whereColumn('newer.rate_type_id', 'co_group_rates.rate_type_id')
                    ->whereColumn('newer.currency', 'co_group_rates.currency')
                    ->whereColumn('newer.effective_date', 'co_group_rates.effective_date')
                    ->whereColumn('newer.version', '>', 'co_group_rates.version'),
            ))
            ->orderByDesc('effective_date')
            ->orderBy('currency')
            ->orderByDesc('version')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return GroupRateResource::collection($rates);
    }

    /**
     * Records the rate of a currency for a rate type and an effective date, as presentation currency per 1
     * unit. A different rate for the same type, currency and date is recorded as a new version (a
     * correction); the same rate again changes nothing and answers 200 with the existing version. The
     * presentation currency itself needs no rate and is refused.
     *
     * @status 201
     *
     * @response GroupRateResource
     */
    public function store(RecordGroupRateRequest $request, RecordGroupRate $record, string $group): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $record(
            $group,
            $request->string('rate_type_id')->toString(),
            $request->string('currency')->toString(),
            $request->string('effective_date')->toString(),
            $request->string('rate')->toString(),
            GroupRate::MANUAL,
            $user->id,
        );

        return (new GroupRateResource($result['rate']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }
}
