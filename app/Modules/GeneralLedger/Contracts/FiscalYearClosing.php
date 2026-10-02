<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Closing of a fiscal year (GL-FR-12, GL-FR-13).
 */
interface FiscalYearClosing
{
    /**
     * Posts the closing journal `system.closing` dated the last day of the year: every income and
     * expense account is brought to zero per dimension set against the `retained_earnings` account.
     * Balance sheet accounts carry on into the next year unchanged. A year closed already returns its
     * run. Nothing of the journals of the year is changed.
     *
     * Fails with ApiException NOT_FOUND for an unknown year, UNBALANCED_ENTRY when the ledger does not
     * balance, MAPPING_REQUIRED when `retained_earnings` is not mapped, and the codes of
     * PostingService::post(), among them PERIOD_NOT_OPEN when the last period does not accept it.
     */
    public function close(FiscalYearClose $command): ClosingRunData;

    /** The closing in force for the year, or null when it is not closed. */
    public function current(string $companyId, string $fiscalYearId): ?ClosingRunData;
}
