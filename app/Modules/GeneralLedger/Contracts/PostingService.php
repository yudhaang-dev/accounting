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

    /**
     * Posts the mirror image of a journal, linked to it (GL-FR-06); a journal can be reversed once.
     * The original's accounts are not validated again, so a journal stays reversible after its
     * account was deactivated; the period of the reversal date must accept the posting.
     *
     * Fails with ApiException NOT_FOUND (unknown journal), INVALID_STATE_TRANSITION (already
     * reversed), COMPANY_READ_ONLY or PERIOD_NOT_OPEN, or a validation error for a malformed date.
     */
    public function reverse(ReverseJournal $command): PostedJournal;
}
