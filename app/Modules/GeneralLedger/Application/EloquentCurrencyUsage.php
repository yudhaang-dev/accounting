<?php

namespace App\Modules\GeneralLedger\Application;

use App\Support\Currency\CurrencyUsage;
use Illuminate\Support\Facades\DB;

/**
 * A currency is used once a journal line carries it. Reads through the query builder, filtered by
 * the company passed in (P-05).
 */
final class EloquentCurrencyUsage implements CurrencyUsage
{
    public function isUsed(string $companyId, string $currency): bool
    {
        return DB::table('gl_journal_lines')->where('company_id', $companyId)->where('currency', $currency)->exists();
    }
}
