<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Access\DataScope;

/**
 * Reads the ledger through a report filter and the data scope of the user (RP-FR-04, IA-FR-03). The
 * dimension sets that the filter and the scope allow are resolved once, so a report with several
 * columns asks MasterData once.
 */
final class ReportLedger
{
    public function __construct(
        private readonly LedgerReader $ledger,
        private readonly DimensionSets $dimensionSets,
    ) {}

    public function slice(string $companyId, ReportFilter $filter, DataScope $scope): LedgerSlice
    {
        $setIds = $filter->dimensions === [] ? null : $this->dimensionSets->setIdsMatching($companyId, $filter->dimensions);

        if ($scope->isRestricted()) {
            $inScope = $this->dimensionSets->setIdsWithin($companyId, $scope->allowed());
            $setIds = $setIds === null ? $inScope : array_values(array_intersect($setIds, $inScope));
        }

        return new LedgerSlice($this->ledger, $companyId, $filter, $setIds);
    }
}
