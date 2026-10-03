<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * One step an event took, as the changes feed shows it (TE-FR-15): what was done (`action`) and where
 * the event stood after it (`status`), with its journal once that status is `posted`.
 */
final readonly class FinancialEventChange
{
    public function __construct(
        public string $cursor,
        public string $eventId,
        public string $action,
        public string $status,
        public ?string $journalId,
        public ?string $journalNumber,
        public string $occurredAt,
    ) {}
}
