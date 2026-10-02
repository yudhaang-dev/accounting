<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One line of a posting command. Amounts are decimal strings (API-TR-05); exactly one of `debit` and
 * `credit` is positive and the other is zero. They are in the transaction `currency`: the functional
 * currency unless another is given, in which case the ledger converts them (GL-FR-08).
 */
final readonly class PostJournalLine
{
    /**
     * @param  string|null  $currency  transaction currency; null means the functional currency
     * @param  string|null  $dimensionSetId  an existing set; wins over `$dimensions`. With neither, the empty set
     * @param  array<string, string|null>|null  $dimensions  dimension type key => value ID, resolved on the accounting date (MD-FR-04)
     * @param  string|null  $exchangeRate  functional currency per 1 unit of `$currency`, for a foreign line; wins over `$rateTypeKey` and is recorded without a rate type or date
     * @param  string|null  $rateTypeKey  rate type to look the rate up in on the accounting date (MD-FR-08); required for a foreign line without `$exchangeRate`
     */
    public function __construct(
        public string $accountId,
        public string $debit,
        public string $credit,
        public ?string $description = null,
        public ?string $currency = null,
        public ?string $dimensionSetId = null,
        public ?array $dimensions = null,
        public ?string $exchangeRate = null,
        public ?string $rateTypeKey = null,
    ) {}
}
