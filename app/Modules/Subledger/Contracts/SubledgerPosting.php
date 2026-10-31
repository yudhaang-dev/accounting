<?php

namespace App\Modules\Subledger\Contracts;

use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;

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

    /**
     * Checks what posting a transfer between cash accounts will check: both accounts exist, are active and
     * differ, the journal credits the one the cash leaves and debits the one it enters, each in the currency
     * of its account, and it touches no other cash or control account; then the journal itself. Nothing is
     * stored.
     *
     * Fails with a validation error keyed `cash_account_id`, `counterpart_cash_account_id` or `lines`, or with
     * any error of the ledger.
     */
    public function validateTransfer(CashTransfer $transfer): void;

    /**
     * Posts the journal of a transfer and records the cash that left one account and entered the other.
     * Posting the same entry again returns its journal and records nothing more.
     */
    public function postTransfer(CashTransfer $transfer): PostedJournal;

    /**
     * Checks what posting cash without a party will check (SL-FR-11): the cash account exists and is active,
     * the journal's lines to its account all stand on one side and in its currency, and it touches no
     * control account and no other cash account; then the journal itself. Nothing is stored.
     *
     * Fails with a validation error keyed `cash_account_id` or `lines`, or with any error of the ledger.
     */
    public function validateCashEntry(CashEntry $entry): void;

    /**
     * Posts the journal of a cash entry and records its cash, in when the lines to the cash account debit
     * it and out when they credit it. Posting the same entry again returns its journal and records nothing
     * more.
     */
    public function postCashEntry(CashEntry $entry): PostedJournal;

    /**
     * Checks what reversing the journal of a document will check (SL-FR-12): the ledger's own rules for a
     * reversal, that the journal is one a document was posted with, and that the document has no active
     * allocation. Nothing is stored.
     *
     * Fails with ApiException NOT_FOUND, INVALID_STATE_TRANSITION (reversed already, not the journal of a
     * document, active allocations), COMPANY_READ_ONLY or PERIOD_NOT_OPEN.
     */
    public function validateReversal(ReverseJournal $reversal): void;

    /**
     * Reverses the journal of a document through the ledger and takes the document out of the open items,
     * returning its cash. Fails like `validateReversal()`; reversing again with the same entry returns the
     * reversal that exists.
     */
    public function reverse(ReverseJournal $reversal): PostedJournal;
}
