<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\ManageCashAccounts;
use App\Modules\Subledger\Domain\CashAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashAccountController
{
    /**
     * Cash and bank accounts of the company, ordered by name. Filters: `filter[status]`,
     * `filter[kind]` (`cash` or `bank`). `per_page` defaults to 50, at most 200.
     */
    public function index(ListCashAccountsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $accounts = CashAccount::query()
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->when(isset($filter['kind']), fn ($query) => $query->where('kind', $filter['kind']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return CashAccountResource::collection($accounts);
    }

    public function show(string $companyId, string $id): CashAccountResource
    {
        return new CashAccountResource(CashAccount::query()->findOrFail($id));
    }

    /**
     * Adds a cash box or bank account. Its currency must be activated for the company, and its
     * ledger account must be an active asset leaf that accepts the `subledger` source only and the
     * currency, and that no other cash account uses. A bank account needs `bank_name` and
     * `account_number`; a cash box has neither.
     */
    public function store(CreateCashAccountRequest $request, ManageCashAccounts $accounts): JsonResponse
    {
        return (new CashAccountResource($accounts->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Changes the name or the bank details. The kind, currency and ledger account cannot change.
     * `lock_version` is required.
     */
    public function update(UpdateCashAccountRequest $request, ManageCashAccounts $accounts, string $companyId, string $id): CashAccountResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new CashAccountResource($accounts->update($id, $data, $expected));
    }

    /**
     * Deactivates the account. Its history stays readable.
     */
    public function deactivate(ManageCashAccounts $accounts, string $companyId, string $id): CashAccountResource
    {
        return new CashAccountResource($accounts->deactivate($id));
    }

    /**
     * Reactivates the account.
     */
    public function reactivate(ManageCashAccounts $accounts, string $companyId, string $id): CashAccountResource
    {
        return new CashAccountResource($accounts->reactivate($id));
    }
}
