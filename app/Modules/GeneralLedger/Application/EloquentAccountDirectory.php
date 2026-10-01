<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\AccountStatus;
use App\Support\Company\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads accounts for the given company explicitly, independent of the request company context.
 */
final class EloquentAccountDirectory implements AccountDirectory
{
    public function find(string $companyId, string $accountId): ?AccountData
    {
        $account = $this->forCompany($companyId)->find($accountId);

        if ($account === null) {
            return null;
        }

        return new AccountData(
            $account->id,
            $account->company_id,
            $account->code,
            $account->name,
            $account->class,
            $account->normal_balance,
            $account->parent_id,
            ! $account->children()->withoutGlobalScope(CompanyScope::class)->exists(),
            $account->isActive(),
        );
    }

    public function missingClasses(string $companyId): array
    {
        $present = $this->forCompany($companyId)
            ->where('status', AccountStatus::Active->value)
            ->whereDoesntHave('children', fn (Builder $children) => $children->withoutGlobalScope(CompanyScope::class))
            ->toBase()
            ->distinct()
            ->pluck('class')
            ->all();

        return array_values(array_filter(
            AccountClass::cases(),
            fn (AccountClass $class): bool => ! in_array($class->value, $present, true),
        ));
    }

    /** @return Builder<Account> */
    private function forCompany(string $companyId): Builder
    {
        return Account::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);
    }
}
