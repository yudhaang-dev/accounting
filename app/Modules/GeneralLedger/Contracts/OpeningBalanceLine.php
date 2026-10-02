<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One balance of the source system. Exactly one of `debit` and `credit` is positive; amounts are
 * decimal strings in `currency` (API-TR-05), the functional currency unless another is given.
 */
final readonly class OpeningBalanceLine
{
    /**
     * @param  string  $sourceAccountCode  code of the account in the source system; must be mapped
     * @param  string|null  $exchangeRate  rate the source carries for a foreign currency line; required for one
     * @param  array<string, string>|null  $dimensions  dimension type key => code of the value in the source system; each must be mapped
     */
    public function __construct(
        public string $sourceAccountCode,
        public string $debit,
        public string $credit,
        public ?string $currency = null,
        public ?string $exchangeRate = null,
        public ?array $dimensions = null,
        public ?string $description = null,
    ) {}
}
