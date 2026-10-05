<?php

namespace App\Modules\Subledger\Contracts;

use App\Modules\GeneralLedger\Contracts\PostJournal;

/**
 * A document of the subledger together with the journal it posts (SL-FR-04). The journal carries the
 * lines the entry made by its posting rules; the subledger finds the control account and cash account
 * lines in it and records the open item and the cash transaction from what the ledger stores.
 */
final readonly class SubledgerDocument
{
    /**
     * @param  string|null  $cashAccountId  required for a kind that moves cash, and only for those
     * @param  string|null  $documentNumber  the number the source gave the document; the journal number when empty
     * @param  string|null  $dueDate  `YYYY-MM-DD`; the document date when empty
     * @param  PostJournal  $journal  posted with the source `subledger`, and with the entry as its idempotency key
     */
    public function __construct(
        public DocumentKind $kind,
        public ControlRole $role,
        public string $partyId,
        public PostJournal $journal,
        public ?string $cashAccountId = null,
        public ?string $documentNumber = null,
        public ?string $dueDate = null,
    ) {}
}
