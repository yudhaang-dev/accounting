<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\ClosingRunData;

/**
 * Where a fiscal year stands in its closing.
 */
final readonly class FiscalYearReadinessData
{
    /**
     * @param  ClosingRunData|null  $closing  the closing in force; null while the year is open
     * @param  bool  $ready  every check passes
     * @param  bool  $retainedEarningsMapped  the `retained_earnings` role is mapped
     * @param  bool  $lastPeriodAccepts  the last period takes the closing journal
     * @param  int  $unfinishedEntries  entries dated up to the end of the year that are not posted or cancelled
     * @param  int  $reconciliationDifferences  control and cash accounts that differ from their subledger at the end of the year, with no waiver (SL-FR-09)
     */
    public function __construct(
        public ?ClosingRunData $closing,
        public bool $ready,
        public bool $retainedEarningsMapped,
        public bool $lastPeriodAccepts,
        public ?string $lastPeriodName,
        public ?string $lastPeriodStatus,
        public int $unfinishedEntries,
        public int $reconciliationDifferences = 0,
    ) {}
}
