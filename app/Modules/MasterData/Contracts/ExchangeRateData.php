<?php

namespace App\Modules\MasterData\Contracts;

/**
 * A rate found by `ExchangeRates::find()`: the functional currency per 1 unit of `$currency`.
 */
final readonly class ExchangeRateData
{
    /**
     * @param  string  $rate  decimal string, e.g. `16250.50`
     * @param  string  $effectiveDate  `YYYY-MM-DD`; the date of the rate, not the date asked for
     */
    public function __construct(
        public string $id,
        public string $rateTypeId,
        public string $rateTypeKey,
        public string $currency,
        public string $effectiveDate,
        public string $rate,
        public int $version,
    ) {}
}
