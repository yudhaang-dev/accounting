<?php

namespace App\Modules\Reporting\Contracts;

/**
 * What one company hands to a consolidation for a period (CO-FR-03): the balances per account with the range
 * and cutoff date they stand for, whether the books of those periods are closed, the functional currency they
 * are in, how much history the ledger holds for the range (RP-FR-13), where the numbers came from and the
 * version of the mappings that were read. A package is a reading, not a record: it is built on request and
 * never stored by Reporting, so the consolidation keeps what it needs with its own run (CO-FR-09).
 *
 * What the ledger does not hold is never zero: `coverageStatus` says `partial` or `unavailable` and the
 * balances are then only what the ledger knows.
 */
final readonly class ReportPackage
{
    /**
     * @param  string  $cutoffDate  the last day the balances include
     * @param  string  $bookStatus  `closed` when every period of the range is closed, `soft_closed` when the weakest is soft closed, `open` when any is open, `no_periods` when the range holds no whole period
     * @param  list<ReportPackagePeriod>  $periods  the periods inside the range, oldest first
     * @param  string  $coverageStatus  `complete`, `partial` or `unavailable`
     * @param  list<ReportPackageBalance>  $balances  per account, by code
     * @param  bool  $balanced  debit equals credit in every column, as the trial balance states
     * @param  array<string, int|null>  $mappingVersions  account role key => the version in force at the cutoff date, null when never mapped
     * @param  array<string, mixed>  $sourceReferences  the report the balances were read from, its parameters and the opening balance batch of a migrated company
     * @param  list<ReportPackageIntercompany>  $intercompany  the part of the balances that is held against other group companies, by counterparty then account code (CO-FR-07)
     */
    public function __construct(
        public string $companyId,
        public string $from,
        public string $to,
        public string $cutoffDate,
        public string $functionalCurrency,
        public string $bookStatus,
        public array $periods,
        public ?string $coverageFrom,
        public ?string $coverageTo,
        public string $coverageGranularity,
        public string $coverageStatus,
        public array $balances,
        public bool $balanced,
        public array $mappingVersions,
        public array $sourceReferences,
        public array $intercompany = [],
    ) {}

    public function isClosed(): bool
    {
        return $this->bookStatus === 'closed';
    }

    public function isComplete(): bool
    {
        return $this->coverageStatus === 'complete';
    }
}
