<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * Where a financial event stands (API-TR-19): the ID of its entry, the status of the entry and, once
 * posted, its journal. `replayed` is set when the event had been received before with the same content
 * (TE-FR-13) and this is the original result.
 */
final readonly class FinancialEventResult
{
    public function __construct(
        public string $eventId,
        public string $status,
        public ?string $journalId,
        public ?string $journalNumber,
        public bool $replayed = false,
    ) {}
}
