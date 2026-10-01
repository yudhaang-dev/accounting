<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Posts balanced journals (GL-FR-05). The only way to create a journal: it validates, numbers,
 * stores and updates the aggregated balances atomically, and returns the existing journal when the
 * same `entryId` is posted again. Safe to call inside the caller's transaction.
 */
interface PostingService
{
    /**
     * Fails with ApiException COMPANY_READ_ONLY, PERIOD_NOT_OPEN, ACCOUNT_NOT_POSTABLE,
     * DIMENSION_INVALID or UNBALANCED_ENTRY, or a validation error for a malformed command; nothing
     * is stored in that case.
     */
    public function post(PostJournal $command): PostedJournal;
}
