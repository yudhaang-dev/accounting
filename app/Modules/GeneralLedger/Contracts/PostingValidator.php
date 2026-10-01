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
}
