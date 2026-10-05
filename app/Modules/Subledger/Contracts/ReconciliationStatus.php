<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Where the reconciliation of the control and cash accounts stands on a date, for the readiness to close a
 * fiscal year (SL-FR-09, GL-FR-12).
 */
interface ReconciliationStatus
{
    /**
     * The accounts that differ from their subledger on the date and whose difference no waiver covers. A waiver
     * covers the account, the date and the very difference it was given for; a difference that changed since
     * needs a new one. Worked out now, not read from earlier runs.
     *
     * @param  string  $asOf  `YYYY-MM-DD`
     * @return list<ReconciliationDifference>
     */
    public function unresolved(string $companyId, string $asOf): array;
}
