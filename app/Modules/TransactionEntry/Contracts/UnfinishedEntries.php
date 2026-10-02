<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * Entries that have not reached a final state, for the readiness of a fiscal year to close (GL-FR-12).
 */
interface UnfinishedEntries
{
    /**
     * Entries of the company dated up to and including `$date` that are neither posted nor cancelled:
     * drafts, submitted, approved and rejected entries. Counts all of them, whatever the data scope of
     * the caller.
     *
     * @param  string  $date  `YYYY-MM-DD`
     */
    public function countThrough(string $companyId, string $date): int;
}
