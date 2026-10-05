<?php

namespace App\Modules\Subledger\Contracts;

/**
 * Validates and records the documents of entries that have a subledger route, then posts their journal
 * through the ledger (SL-FR-04, TE-FR-10). The document, its open item and cash transaction, and the
 * journal are written in the caller's transaction or none of them is. Safe to call inside it.
 */
interface SubledgerPosting
{
    /**
     * Checks what posting will check: the party and its role, the control and cash accounts, the shape of
     * the lines, and then the journal itself (GL-FR-05). Nothing is stored.
     *
     * Fails with a validation error keyed `party_id`, `cash_account_id` or `lines`, with ApiException
     * MAPPING_REQUIRED when the control account is not mapped, or with any error of the ledger.
     */
    public function validate(SubledgerDocument $document): void;

    /**
     * Fails like `validate()`. Posting the same entry again returns the document that exists, with its
     * journal.
     */
    public function post(SubledgerDocument $document): PostedDocument;
}
