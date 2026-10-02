<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to reverse one posted journal (GL-FR-06). The reversal copies accounts, dimension sets,
 * transaction amounts and rates of the original with debit and credit swapped.
 */
final readonly class ReverseJournal
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`; decides the period of the reversal
     * @param  string|null  $description  defaults to a text naming the number of the original
     * @param  string|null  $entryId  idempotency key: reversing again with the same key returns the reversal that exists
     * @param  list<string>  $actorPermissions  permissions the application layer found the actor to hold (GL-FR-04)
     */
    public function __construct(
        public string $companyId,
        public string $journalId,
        public string $accountingDate,
        public ?string $description = null,
        public ?string $entryId = null,
        public ?int $postedBy = null,
        public array $actorPermissions = [],
    ) {}
}
