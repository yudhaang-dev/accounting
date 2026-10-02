<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to reopen a closed fiscal year (GL-FR-14).
 */
final readonly class FiscalYearReopen
{
    /**
     * @param  string  $reason  why the year is reopened; kept on the closing run and in the audit log
     * @param  list<string>  $actorPermissions  permissions the application layer found the actor to hold
     */
    public function __construct(
        public string $companyId,
        public string $fiscalYearId,
        public string $reason,
        public int $reopenedBy,
        public array $actorPermissions = [],
    ) {}
}
