<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class JournalController
{
    public function __construct(
        private readonly DimensionSets $dimensionSets,
        private readonly CompanyContext $company,
    ) {}

    /**
     * Posted journals with their lines, newest first. This is the drill-down of the reports.
     * Filters: `filter[account_id]` (journals that touch the account), `filter[from]` and
     * `filter[to]` (accounting date), `filter[source]`, `filter[transaction_type_key]` and
     * `filter[dimensions][<type key>]=<value id>` (journals with a line in that value or below it).
     * Lines carry their `dimensions` as type key => value ID.
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListJournalsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);

        $setIds = isset($filters['dimensions'])
            ? $this->dimensionSets->setIdsMatching($this->company->require(), $filters['dimensions'])
            : null;

        $journals = Journal::query()
            ->with('lines')
            ->when(isset($filters['account_id']) || $setIds !== null, fn (Builder $query) => $query->whereHas('lines', fn (Builder $q) => $q
                ->when(isset($filters['account_id']), fn (Builder $l) => $l->where('account_id', $filters['account_id']))
                ->when($setIds !== null, fn (Builder $l) => $l->whereIn('dimension_set_id', $setIds))))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('accounting_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('accounting_date', '<=', $filters['to']))
            ->when(isset($filters['source']), fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(isset($filters['transaction_type_key']), fn (Builder $query) => $query->where('transaction_type_key', $filters['transaction_type_key']))
            ->orderByDesc('accounting_date')
            ->orderByDesc('number')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        $this->attachDimensions($journals->getCollection());

        return JournalResource::collection($journals);
    }

    public function show(string $companyId, string $id): JournalResource
    {
        $journal = Journal::query()->with('lines')->findOrFail($id);
        $this->attachDimensions(collect([$journal]));

        return new JournalResource($journal);
    }

    /**
     * Gives every line its dimensions, read for the whole page in one query.
     *
     * @param  Collection<int, Journal>  $journals
     */
    private function attachDimensions(Collection $journals): void
    {
        $lines = $journals->flatMap(fn (Journal $journal) => $journal->lines);
        $described = $this->dimensionSets->describe($this->company->require(), array_values(array_map('strval', $lines->pluck('dimension_set_id')->unique()->all())));

        foreach ($lines as $line) {
            $line->setAttribute('dimensions', $described[$line->dimension_set_id] ?? []);
        }
    }
}
