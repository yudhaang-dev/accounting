<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\TranslationPolicy;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts of a group (CO-FR-04). The class of an account is fixed once it exists, because the
 * mappings of company accounts to it were checked against that class; an account no longer wanted is
 * deactivated, never deleted (X-FR-05).
 */
final class ManageGroupAccounts
{
    public function __construct(private readonly Audit $audit) {}

    /** @throws ValidationException */
    public function create(string $groupId, string $code, string $name, string $class, ?string $cashFlowClass = null): GroupAccount
    {
        $this->assertCashFlowClass($class, $cashFlowClass);

        try {
            return DB::transaction(function () use ($groupId, $code, $name, $class, $cashFlowClass): GroupAccount {
                $group = Group::query()->lockForUpdate()->findOrFail($groupId);
                $account = GroupAccount::create(['group_id' => $group->id, 'code' => $code, 'name' => $name, 'class' => $class, 'cash_flow_class' => $cashFlowClass])->refresh();

                $this->audit->record('group_account.created', "Group account {$code} created.", $account, after: $this->snapshot($account), properties: ['group_id' => $group->id], companyId: $group->parent_company_id);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => [__('consolidation.group_account_code_taken')]]);
        }
    }

    /**
     * @param  array<string, mixed>  $changes  `name`, `is_active` and `cash_flow_class`
     *
     * @throws ApiException VERSION_CONFLICT
     */
    public function update(string $groupId, string $accountId, array $changes, int $expectedVersion): GroupAccount
    {
        return DB::transaction(function () use ($groupId, $accountId, $changes, $expectedVersion): GroupAccount {
            $account = GroupAccount::query()->where('group_id', $groupId)->lockForUpdate()->findOrFail($accountId);
            $before = $this->snapshot($account);

            if (($changes['is_active'] ?? true) === false && TranslationPolicy::query()->where('group_id', $groupId)->orderByDesc('version')->value('difference_account_id') === $account->id) {
                throw ValidationException::withMessages(['is_active' => [__('consolidation.group_account_in_policy')]]);
            }

            if (array_key_exists('cash_flow_class', $changes)) {
                $this->assertCashFlowClass($account->class, $changes['cash_flow_class']);
            }

            $account->updateLocked(array_intersect_key($changes, array_flip(['name', 'is_active', 'cash_flow_class'])), $expectedVersion);

            if ($before !== $this->snapshot($account)) {
                $this->audit->record('group_account.updated', "Group account {$account->code} updated.", $account, before: $before, after: $this->snapshot($account), properties: ['group_id' => $groupId], companyId: Group::query()->whereKey($groupId)->value('parent_company_id'));
            }

            return $account->refresh();
        });
    }

    /**
     * Only assets, liabilities and equity enter the cash flow by their changes, and only an asset can be cash.
     *
     * @throws ValidationException
     */
    private function assertCashFlowClass(string $class, ?string $cashFlowClass): void
    {
        if ($cashFlowClass === null) {
            return;
        }

        if (! in_array($class, ['asset', 'liability', 'equity'], true) || ($cashFlowClass === 'cash' && $class !== 'asset')) {
            throw ValidationException::withMessages(['cash_flow_class' => [__('consolidation.cash_flow_class_invalid')]]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(GroupAccount $account): array
    {
        return ['code' => $account->code, 'name' => $account->name, 'class' => $account->class, 'is_active' => $account->is_active, 'cash_flow_class' => $account->cash_flow_class];
    }
}
