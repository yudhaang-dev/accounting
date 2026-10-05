<?php

namespace App\Modules\Subledger\Contracts;

use App\Modules\GeneralLedger\Contracts\PostJournal;

/**
 * Cash moved from one cash account to another, each in its own currency (SL-FR-03, SL-FR-11). It has no
 * party and no document: the subledger records the cash that left one account and the cash that entered
 * the other, from the journal.
 */
final readonly class CashTransfer
{
    /**
     * @param  PostJournal  $journal  posted with the source `subledger`, and with the entry as its idempotency key; credits the account the cash leaves and debits the one it enters, and may carry the exchange difference as a line of its own
     */
    public function __construct(
        public PostJournal $journal,
        public string $fromCashAccountId,
        public string $toCashAccountId,
    ) {}
}
