<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to close a fiscal year in the ledger (GL-FR-12). Whether the rest of the system is ready
 * for it is decided by the use case that sends the command; the ledger checks its own invariants.
 */
final readonly class FiscalYearClose
{
    /** @param  list<string>  $actorPermissions  permissions the application layer found the actor to hold */
    public function __construct(
        public string $companyId,
        public string $fiscalYearId,
        public int $closedBy,
        public array $actorPermissions = [],
    ) {}
}
