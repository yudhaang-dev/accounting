<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Support\Company\CompanyScope;

final class EloquentTransactionTypeKeys implements TransactionTypeKeys
{
    public function missing(string $companyId, array $keys): array
    {
        $known = TransactionType::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->whereIn('key', $keys)
            ->pluck('key')
            ->all();

        return array_values(array_diff($keys, $known));
    }
}
