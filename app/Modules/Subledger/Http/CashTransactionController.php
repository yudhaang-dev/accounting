<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\ClearCashTransactions;
use App\Modules\Subledger\Domain\CashTransaction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashTransactionController
{
    /**
     * Cash transactions, newest first. Filters: `filter[cash_account_id]`, `filter[cleared]`,
     * `filter[date_from]` and `filter[date_to]` on the transaction date. `per_page` defaults to 50,
     * at most 200. Transactions are recorded by the documents that move cash, not through this API.
     */
    public function index(ListCashTransactionsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $transactions = CashTransaction::query()
            ->when(isset($filter['cash_account_id']), fn ($query) => $query->where('cash_account_id', $filter['cash_account_id']))
            ->when(isset($filter['cleared']), fn ($query) => $query->whereNull('cleared_on', not: (bool) $filter['cleared']))
            ->when(isset($filter['date_from']), fn ($query) => $query->whereDate('transaction_date', '>=', $filter['date_from']))
            ->when(isset($filter['date_to']), fn ($query) => $query->whereDate('transaction_date', '<=', $filter['date_to']))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return CashTransactionResource::collection($transactions);
    }

    public function show(string $companyId, string $id): CashTransactionResource
    {
        return new CashTransactionResource(CashTransaction::query()->findOrFail($id));
    }

    /**
     * Marks the transaction cleared on `cleared_on` (today when empty, never in the future or before
     * the transaction date). The date and the user are kept and the change is audited.
     */
    public function clear(ClearCashTransactionRequest $request, ClearCashTransactions $clearing, string $companyId, string $id): CashTransactionResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{cleared_on?: string|null} $data */
        $data = $request->validated();

        return new CashTransactionResource($clearing->clear($id, $data['cleared_on'] ?? now()->toDateString(), $user->id));
    }

    /**
     * Takes the cleared mark off the transaction; the change is audited.
     */
    public function unclear(ClearCashTransactions $clearing, string $companyId, string $id): CashTransactionResource
    {
        return new CashTransactionResource($clearing->unclear($id));
    }
}
