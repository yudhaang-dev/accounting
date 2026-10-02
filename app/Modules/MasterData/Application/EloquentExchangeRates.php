<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\ExchangeRateData;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\MasterData\Domain\ExchangeRate;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads through the query builder, so it works without a company context: callers pass the company
 * explicitly and every query is filtered by it (P-05).
 */
final class EloquentExchangeRates implements ExchangeRates
{
    public function find(string $companyId, string $rateTypeKey, string $currency, string $date): ExchangeRateData
    {
        $type = DB::table('md_rate_types')->where('company_id', $companyId)->where('key', $rateTypeKey)->first()
            ?? throw new ApiException(ErrorCode::ExchangeRateNotFound, __('rates.type_unknown', ['key' => $rateTypeKey]));

        $row = DB::table('md_exchange_rates')
            ->where('company_id', $companyId)
            ->where('rate_type_id', $type->id)
            ->where('currency', $currency)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            throw new ApiException(ErrorCode::ExchangeRateNotFound, __('rates.not_found', ['type' => $type->label, 'currency' => $currency, 'date' => $date]));
        }

        $effective = (string) $row->effective_date;

        if ($type->max_age_days !== null && CarbonImmutable::parse($effective)->diffInDays(CarbonImmutable::parse($date)) > $type->max_age_days) {
            throw new ApiException(ErrorCode::ExchangeRateNotFound, __('rates.too_old', [
                'type' => $type->label, 'currency' => $currency, 'date' => $date, 'effective' => $effective, 'days' => $type->max_age_days,
            ]));
        }

        return new ExchangeRateData(
            (string) $row->id,
            (string) $type->id,
            $rateTypeKey,
            $currency,
            $effective,
            ExchangeRate::format(BigDecimal::of($row->rate)),
            (int) $row->version,
        );
    }
}
