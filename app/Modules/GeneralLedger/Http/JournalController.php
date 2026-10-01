<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Journal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalController
{
    /**
     * Posted journals with their lines, newest first. This is the drill-down of the reports.
     * Filters: `filter[account_id]` (journals that touch the account), `filter[from]` and
     * `filter[to]` (accounting date), `filter[source]` and `filter[transaction_type_key]`.
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListJournalsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);

        $journals = Journal::query()
            ->with('lines')
            ->when(isset($filters['account_id']), fn (Builder $query) => $query->whereHas('lines', fn (Builder $q) => $q->where('account_id', $filters['account_id'])))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('accounting_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('accounting_date', '<=', $filters['to']))
            ->when(isset($filters['source']), fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(isset($filters['transaction_type_key']), fn (Builder $query) => $query->where('transaction_type_key', $filters['transaction_type_key']))
            ->orderByDesc('accounting_date')
            ->orderByDesc('number')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return JournalResource::collection($journals);
    }

    public function show(string $companyId, string $id): JournalResource
    {
        return new JournalResource(Journal::query()->with('lines')->findOrFail($id));
    }
}
