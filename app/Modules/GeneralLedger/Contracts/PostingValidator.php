<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Validates a posting command without posting it: company, structure, period, accounts, currency,
 * dimensions and balance (GL-FR-05). Used by `TransactionEntry` to refuse an entry early; no
 * journal, number or balance is touched.
 */
interface PostingValidator
{
    /**
     * Fails with the same ApiException codes or validation error as PostingService::post().
     */
    public function validate(PostJournal $command): void;

    /**
     * Checks a reversal the way `PostingService::reverse()` will: the journal exists, has not been
     * reversed, the date is valid and its period accepts the posting. Nothing is stored.
     *
     * Fails with ApiException NOT_FOUND, INVALID_STATE_TRANSITION, COMPANY_READ_ONLY or PERIOD_NOT_OPEN,
     * or a validation error for a malformed date.
     */
    public function validateReversal(ReverseJournal $command): void;
}
