<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Modules\TransactionEntry\Domain\WithinDataScope;
use App\Support\Company\CompanyScope;

final class EloquentUnfinishedEntries implements UnfinishedEntries
{
    public function countThrough(string $companyId, string $date): int
    {
        return Entry::query()
            ->withoutGlobalScopes([CompanyScope::class, WithinDataScope::class])
            ->where('company_id', $companyId)
            ->where('accounting_date', '<=', $date)
            ->whereNotIn('status', [EntryStatus::Posted->value, EntryStatus::Cancelled->value])
            ->count();
    }
}
