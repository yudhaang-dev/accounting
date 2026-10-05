<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\DimensionRequirement;
use App\Modules\GeneralLedger\Contracts\JournalSource;
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

        return $account === null ? null : $this->data($account);
    }

    public function findByCode(string $companyId, string $code): ?AccountData
    {
        $account = $this->forCompany($companyId)->where('code', $code)->first();

        return $account === null ? null : $this->data($account);
    }

    private function data(Account $account): AccountData
    {
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
            array_map(JournalSource::from(...), $account->allowed_sources),
            array_map(DimensionRequirement::from(...), $account->dimension_requirements),
            $account->currency_restriction,
            $account->restricted_currency,
            $account->is_revaluable,
        );
    }

    public function roleTargetProblems(string $companyId, string $accountId, array $classes, ?JournalSource $requiredSource = null): array
    {
        $account = $this->find($companyId, $accountId);

        if ($account === null) {
            return [__('ledger.account_unknown')];
        }

        $problems = [];

        if (! $account->isActive) {
            $problems[] = __('ledger.account_inactive');
        }

        if (! $account->isLeaf) {
            $problems[] = __('ledger.account_parent');
        }

        if (! in_array($account->class, $classes, true)) {
            $problems[] = __('ledger.role_class_not_allowed', ['class' => $account->class->value, 'allowed' => implode(', ', array_map(fn (AccountClass $class): string => $class->value, $classes))]);
        }

        if ($requiredSource !== null && ! $account->acceptsSource($requiredSource)) {
            $problems[] = __('ledger.source_not_allowed', ['account' => $account->code, 'source' => $requiredSource->value]);
        }

        return $problems;
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
