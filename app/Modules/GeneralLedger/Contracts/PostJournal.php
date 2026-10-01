<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to post one balanced journal (GL-FR-05). The ledger stores the transaction type key and
 * version and the references as opaque metadata and never interprets them.
 */
final readonly class PostJournal
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`; decides the period
     * @param  string|null  $entryId  idempotency key: posting the same entry again returns the journal that exists
     * @param  array<string, mixed>|null  $references  opaque references of the source
     * @param  int|null  $postedBy  user who caused the posting, when there is one
     * @param  list<PostJournalLine>  $lines
     */
    public function __construct(
        public string $companyId,
        public string $accountingDate,
        public string $description,
        public JournalSource $source,
        public array $lines,
        public ?string $documentDate = null,
        public ?string $transactionTypeKey = null,
        public ?int $transactionTypeVersion = null,
        public ?array $references = null,
        public ?string $entryId = null,
        public ?int $postedBy = null,
    ) {}
}
