<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * History of a company that migrates before its cutover date (GL-FR-11).
 */
interface HistoryImports
{
    /**
     * Posts the journals of the history as `system.history_import` and checks that the ledger then
     * carries, per account, the trial balance of the source at the cutover date. Everything is
     * posted or nothing is. Importing the same source key and content again returns the import that
     * exists.
     *
     * Fails with ApiException INVALID_STATE_TRANSITION without an opening balance batch or when the
     * batch has no history to import, or when another import exists, IDEMPOTENCY_KEY_REUSED for a
     * known source key with other content, MAPPING_REQUIRED for a source code without a mapping,
     * the codes of PostingService::post(), and a validation error for dates, granularity and a
     * ledger that differs from the trial balance of the source.
     */
    public function import(HistoryImport $command): HistoryImportData;

    /** The import of the company, or null before it. */
    public function current(string $companyId): ?HistoryImportData;
}
