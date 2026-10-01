<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Application\CreateAccount;
use App\Modules\GeneralLedger\Application\SetAccountStatus;
use App\Modules\GeneralLedger\Application\UpdateAccount;
use App\Modules\GeneralLedger\Domain\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AccountController
{
    /**
     * Accounts of the company, ordered by code unless `sort` says otherwise. Filters: `filter[class]`,
     * `filter[status]`, `filter[parent_id]` and `filter[search]` (code prefix or part of the name).
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListAccountsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);
        $sort = $request->validated('sort', 'code');

        $accounts = Account::query()
            ->withExists('children as has_children')
            ->when(isset($filters['class']), fn (Builder $query) => $query->where('class', $filters['class']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['parent_id']), fn (Builder $query) => $query->where('parent_id', $filters['parent_id']))
            ->when(isset($filters['search']), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('code', 'like', $filters['search'].'%')->orWhere('name', 'ilike', '%'.$filters['search'].'%'),
            ))
            ->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return AccountResource::collection($accounts);
    }

    public function show(string $companyId, string $id): AccountResource
    {
        return new AccountResource(Account::query()->findOrFail($id));
    }

    /**
     * Adds an account. The saldo normal defaults to that of the class; the parent must be active and
     * of the same class.
     */
    public function store(CreateAccountRequest $request, CreateAccount $createAccount): JsonResponse
    {
        /** @var array{code: string, name: string, class: string, normal_balance?: string|null, parent_id?: string|null} $data */
        $data = $request->validated();

        return (new AccountResource($createAccount($data)))->response()->setStatusCode(201);
    }

    /**
     * Changes code, name, saldo normal or parent. `lock_version` is required; the class cannot change.
     */
    public function update(UpdateAccountRequest $request, UpdateAccount $updateAccount, string $companyId, string $id): AccountResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new AccountResource($updateAccount($id, $data, $expected));
    }

    /**
     * Deactivates the account. Refused while it has active children.
     */
    public function deactivate(SetAccountStatus $status, string $companyId, string $id): AccountResource
    {
        return new AccountResource($status->deactivate($id));
    }

    /**
     * Reactivates the account. Refused while its parent is inactive.
     */
    public function reactivate(SetAccountStatus $status, string $companyId, string $id): AccountResource
    {
        return new AccountResource($status->reactivate($id));
    }
}
