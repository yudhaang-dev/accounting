<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class ClosingRunData
{
    /**
     * @param  string|null  $journalId  null for a year without income or expense to close
     * @param  string  $result  profit (positive) or loss (negative) of the year moved to retained earnings, in the functional currency
     * @param  bool  $replayed  the year was closed already, nothing was posted
     * @param  string|null  $reversalJournalId  journal that reversed the closing journal when the year was reopened
     */
    public function __construct(
        public string $id,
        public string $fiscalYearId,
        public string $status,
        public ?string $journalId,
        public ?string $journalNumber,
        public int $lineCount,
        public string $result,
        public int $retainedEarningsMappingVersion,
        public string $createdAt,
        public bool $replayed = false,
        public ?string $reversalJournalId = null,
    ) {}
}
