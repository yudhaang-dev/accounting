<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The open items of the conversion date and what they add up to per control account, for the opening
 * balance batch of the ledger to be held to (SL-FR-10, GL-FR-11). Safe to call inside the caller's transaction.
 */
interface OpeningOpenItems
{
    /**
     * Checks the items and works out their total per control account: net debit less credit in the functional
     * currency, a decimal string by control account ID. Nothing is stored.
     *
     * Fails with a validation error keyed `open_items.<index>.<field>`, or ApiException MAPPING_REQUIRED when a
     * control account is not mapped.
     *
     * @return array<string, string>
     */
    public function totals(OpeningOpenItemBatch $batch): array;

    /**
     * Records the items as documents of the opening balance journal, each with its open item. Checked as
     * `totals()` is. Returns the number of open items recorded.
     */
    public function record(OpeningOpenItemBatch $batch, string $journalId): int;
}
