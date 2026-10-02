<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Access\DataScopeContext;
use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class JournalController
{
    public function __construct(
        private readonly DimensionSets $dimensionSets,
        private readonly Currencies $currencies,
        private readonly CompanyContext $company,
        private readonly DataScopeContext $scope,
    ) {}

    /**
     * Posted journals with their lines, newest first. This is the drill-down of the reports.
     * Filters: `filter[account_id]` (journals that touch the account), `filter[from]` and
     * `filter[to]` (accounting date), `filter[source]`, `filter[transaction_type_key]` and
     * `filter[dimensions][<type key>]=<value id>` (journals with a line in that value or below it).
     * Lines carry their `dimensions` as type key => value ID.
     * A user limited to some dimension values (IA-FR-03) sees only journals with a line inside that
     * scope, and only those lines; such journals have `scope_restricted` true.
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListJournalsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);
        $scopeSetIds = $this->scopeSetIds();

        $setIds = isset($filters['dimensions'])
            ? $this->dimensionSets->setIdsMatching($this->company->require(), $filters['dimensions'])
            : null;

        if ($scopeSetIds !== null) {
            $setIds = $setIds === null ? $scopeSetIds : array_values(array_intersect($setIds, $scopeSetIds));
        }

        $journals = Journal::query()
            ->with(['lines' => fn ($lines) => $lines->when($scopeSetIds !== null, fn (Builder $q) => $q->whereIn('dimension_set_id', $scopeSetIds))])
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

        $this->prepare($journals->getCollection(), $scopeSetIds !== null);

        return JournalResource::collection($journals);
    }

    /** One journal with its lines; for a user with a data scope only the lines inside it, and 404 when there are none. */
    public function show(string $companyId, string $id): JournalResource
    {
        $scopeSetIds = $this->scopeSetIds();

        $journal = Journal::query()
            ->with(['lines' => fn ($lines) => $lines->when($scopeSetIds !== null, fn (Builder $q) => $q->whereIn('dimension_set_id', $scopeSetIds))])
            ->when($scopeSetIds !== null, fn (Builder $query) => $query->whereHas('lines', fn (Builder $q) => $q->whereIn('dimension_set_id', $scopeSetIds)))
            ->findOrFail($id);
        $this->prepare(collect([$journal]), $scopeSetIds !== null);

        return new JournalResource($journal);
    }

    /**
     * IDs of the dimension sets inside the data scope of the user, or null when there is no limit.
     *
     * @return list<string>|null
     */
    private function scopeSetIds(): ?array
    {
        $scope = $this->scope->current();

        return $scope->isRestricted() ? $this->dimensionSets->setIdsWithin($this->company->require(), $scope->allowed()) : null;
    }

    /**
     * Gives every line its dimensions, read for the whole page in one query, and every journal the
     * amount scale of the functional currency.
     *
     * @param  Collection<int, Journal>  $journals
     */
    private function prepare(Collection $journals, bool $scopeRestricted): void
    {
        $scale = $this->currencies->functionalScale($this->company->require());
        $journals->each(fn (Journal $journal) => $journal->setAttribute('amount_scale', $scale)->setAttribute('scope_restricted', $scopeRestricted));

        $lines = $journals->flatMap(fn (Journal $journal) => $journal->lines);
        $described = $this->dimensionSets->describe($this->company->require(), array_values(array_map('strval', $lines->pluck('dimension_set_id')->unique()->all())));

        foreach ($lines as $line) {
            $line->setAttribute('dimensions', $described[$line->dimension_set_id] ?? []);
        }
    }
}
