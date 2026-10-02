<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Looks up posted journals for other modules (GL-FR-06). Read only.
 */
interface JournalDirectory
{
    /** The journal, or null when the company has none with that ID or the caller sees none of its lines. */
    public function find(string $companyId, string $journalId): ?JournalData;
}
