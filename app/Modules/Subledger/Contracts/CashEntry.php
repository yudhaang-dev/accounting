<?php

namespace App\Modules\Subledger\Contracts;

use App\Modules\GeneralLedger\Contracts\PostJournal;

/**
 * Cash that moves in or out of one cash account with no party behind it, such as a cash expense, a cash
 * capital contribution, or bank interest (SL-FR-03, SL-FR-11). It has no document, control account, or open
 * item: the subledger records the cash of the journal and nothing else.
 */
final readonly class CashEntry
{
    /**
     * @param  PostJournal  $journal  posted with the source `subledger`, and with the entry as its idempotency key; its lines to the account of the cash account all stand on one side, which gives the direction of the cash
     * @param  string|null  $reference  the reference of the cash transaction, such as the number of a receipt; the number of the journal when empty
     */
    public function __construct(
        public PostJournal $journal,
        public string $cashAccountId,
        public ?string $reference = null,
    ) {}
}
