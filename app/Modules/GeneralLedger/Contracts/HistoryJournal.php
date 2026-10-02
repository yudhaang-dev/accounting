<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One journal of the migrated history: a transaction of the source, or the movement of one period.
 */
final readonly class HistoryJournal
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`; the last day of the period for a period summary
     * @param  list<OpeningBalanceLine>  $lines
     */
    public function __construct(
        public string $accountingDate,
        public array $lines,
        public ?string $description = null,
    ) {}
}
