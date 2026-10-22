<?php

namespace App\Modules\Reporting\Contracts;

/**
 * Builds the report package of a company for `Consolidation` (CO-FR-03). It reads the whole company, as a
 * snapshot does, so who may ask is decided by the caller: a user's grant on the group (IA-FR-07), never the
 * user's membership of the company.
 */
interface ReportPackages
{
    /**
     * The package of the company for `$from`..`$to`, with the balances as of `$to`. A range that is not a valid
     * date range fails validation, and a company that does not exist is NOT_FOUND.
     */
    public function build(string $companyId, string $from, string $to): ReportPackage;
}
