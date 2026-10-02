<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IsoCurrencyController
{
    /**
     * The ISO 4217 reference a company activates currencies from, ordered by code. Filter:
     * `filter[search]` (code prefix or part of the name). `per_page` defaults to 50, at most 200.
     */
    public function index(ListCurrenciesRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('filter.search');

        $currencies = Currency::query()
            ->when($search !== null, fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('code', 'ilike', $search.'%')->orWhere('name', 'ilike', '%'.$search.'%'),
            ))
            ->orderBy('code')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return IsoCurrencyResource::collection($currencies);
    }
}
