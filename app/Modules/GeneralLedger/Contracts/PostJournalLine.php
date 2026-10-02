<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One line of a posting command. Amounts are decimal strings in the functional currency (API-TR-05);
 * exactly one of `debit` and `credit` is positive and the other is zero.
 */
final readonly class PostJournalLine
{
    /**
     * @param  string|null  $currency  transaction currency; null or the functional currency until foreign currency lines arrive (M4-05)
     * @param  string|null  $dimensionSetId  an existing set; wins over `$dimensions`. With neither, the empty set
     * @param  array<string, string|null>|null  $dimensions  dimension type key => value ID, resolved on the accounting date (MD-FR-04)
     */
    public function __construct(
        public string $accountId,
        public string $debit,
        public string $credit,
        public ?string $description = null,
        public ?string $currency = null,
        public ?string $dimensionSetId = null,
        public ?array $dimensions = null,
    ) {}
}
