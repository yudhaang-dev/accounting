<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Opening balances of a company that starts in the middle of its life (GL-FR-11).
 */
interface OpeningBalances
{
    /**
     * Maps, validates and posts the batch as one `system.opening_balance` journal dated the ledger
     * start date, atomically. Control accounts of a subledger accept it although they take no other
     * source, and must equal the totals the command carries. Importing the same source key and
     * content again returns the batch that exists.
     *
     * Fails with ApiException MAPPING_REQUIRED for a source code without a mapping,
     * IDEMPOTENCY_KEY_REUSED for a known source key with other content, INVALID_STATE_TRANSITION
     * when the company already has another batch, the codes of PostingService::post(), and a
     * validation error for dates, duplicate lines and control accounts that differ from their totals.
     */
    public function import(OpeningBalanceImport $command): OpeningBalanceBatchData;

    /** The batch of the company, or null before the import. */
    public function current(string $companyId): ?OpeningBalanceBatchData;
}
