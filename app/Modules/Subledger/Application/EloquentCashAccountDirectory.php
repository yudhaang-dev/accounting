<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\CashAccountData;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\PartyStatus;
use App\Support\Company\CompanyScope;

/**
 * Takes the company explicitly, so other modules can read cash accounts without a request context.
 */
final class EloquentCashAccountDirectory implements CashAccountDirectory
{
    public function find(string $companyId, string $cashAccountId): ?CashAccountData
    {
        $account = CashAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereKey($cashAccountId)->first();

        return $account === null ? null : new CashAccountData($account->id, $account->name, $account->currency, $account->account_id, $account->status === PartyStatus::Active);
    }
}
