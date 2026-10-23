<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Application\ManageGroupAccounts;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupAccountController
{
    /** The chart of accounts of the group, by code, inactive accounts included. */
    public function index(string $group): AnonymousResourceCollection
    {
        return GroupAccountResource::collection(GroupAccount::query()->where('group_id', Group::query()->findOrFail($group)->id)->orderBy('code')->get());
    }

    public function store(CreateGroupAccountRequest $request, ManageGroupAccounts $accounts, string $group): JsonResponse
    {
        $account = $accounts->create($group, $request->string('code')->toString(), $request->string('name')->toString(), $request->string('class')->toString(), $request->filled('cash_flow_class') ? $request->string('cash_flow_class')->toString() : null);

        return (new GroupAccountResource($account))->response()->setStatusCode(201);
    }

    /** Renames or deactivates an account, or sets how it enters the cash flow; its class never changes. */
    public function update(UpdateGroupAccountRequest $request, ManageGroupAccounts $accounts, string $group, string $id): GroupAccountResource
    {
        return new GroupAccountResource($accounts->update($group, $id, $request->safe()->only(['name', 'is_active', 'cash_flow_class']), $request->integer('lock_version')));
    }
}
