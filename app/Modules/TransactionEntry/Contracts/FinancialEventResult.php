<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * Where a financial event stands (API-TR-19): the ID of its entry, the status of the entry and, once
 * posted, its journal.
 */
final readonly class FinancialEventResult
{
    public function __construct(
        public string $eventId,
        public string $status,
        public ?string $journalId,
        public ?string $journalNumber,
    ) {}
}
