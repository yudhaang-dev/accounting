<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Exchange rates of a company (MD-FR-07, MD-FR-08).
 */
interface ExchangeRates
{
    /**
     * The rate of a foreign currency for a date: the latest version of the rate with the latest
     * effective date on or before `$date`, within the age limit of the rate type. Never falls back to
     * 1, another rate type or a cross rate.
     *
     * Fails with ApiException EXCHANGE_RATE_NOT_FOUND when the rate type is unknown, when no rate
     * qualifies, or when the newest one is older than the age limit.
     *
     * @param  string  $date  `YYYY-MM-DD`
     */
    public function find(string $companyId, string $rateTypeKey, string $currency, string $date): ExchangeRateData;
}
