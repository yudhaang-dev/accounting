<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyConfig;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use Illuminate\Support\Facades\DB;

/**
 * Reads through the query builder, so it works without a company context: callers pass the company
 * explicitly and every query is filtered by it (P-05).
 */
final class EloquentCurrencies implements Currencies
{
    public function isKnown(string $code): bool
    {
        return DB::table('md_currencies')->where('code', $code)->exists();
    }

    public function find(string $companyId, string $code): ?CurrencyConfig
    {
        $row = DB::table('md_company_currencies')->where('company_id', $companyId)->where('currency', $code)->first();

        return $row === null ? null : new CurrencyConfig($row->currency, max(0, (int) $row->scale), CurrencyRounding::from($row->rounding_mode), $row->status === 'active');
    }

    public function isActive(string $companyId, string $code): bool
    {
        return $this->find($companyId, $code)?->active === true;
    }

    public function functionalScale(string $companyId): int
    {
        $scale = DB::table('md_company_currencies as cc')
            ->join('md_companies as c', fn ($join) => $join->on('c.id', '=', 'cc.company_id')->on('c.functional_currency', '=', 'cc.currency'))
            ->where('cc.company_id', $companyId)
            ->value('cc.scale');

        return $scale === null ? max(0, (int) config('ledger.default_scale')) : max(0, (int) $scale);
    }
}
