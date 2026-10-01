<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\AccountStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates an account (GL-FR-01). Accounts are never deleted. A parent cannot be
 * deactivated while it has active children, and a child cannot be reactivated under an inactive parent.
 */
final class SetAccountStatus
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function deactivate(string $accountId): Account
    {
        return $this->change($accountId, AccountStatus::Active, AccountStatus::Inactive, 'account.deactivated', function (Account $account): bool {
            return ! $account->children()->where('status', AccountStatus::Active->value)->exists();
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function reactivate(string $accountId): Account
    {
        return $this->change($accountId, AccountStatus::Inactive, AccountStatus::Active, 'account.reactivated', function (Account $account): bool {
            return $account->parent_id === null || Account::query()->find($account->parent_id)?->isActive() === true;
        });
    }

    /**
     * @param  callable(Account): bool  $allowed
     */
    private function change(string $accountId, AccountStatus $from, AccountStatus $to, string $event, callable $allowed): Account
    {
        return DB::transaction(function () use ($accountId, $from, $to, $event, $allowed): Account {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);

            if ($account->status !== $from || ! $allowed($account)) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $account->status = $to;
            $account->save();

            $this->audit->record(
                $event,
                "Account {$account->code} changed from {$from->value} to {$to->value}.",
                $account,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $account->company_id,
            );

            return $account;
        });
    }
}
