<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Reads posted ledger data for reports (RP-FR-05). Read only; reports never touch ledger tables.
 */
interface LedgerReader
{
    /**
     * Balances per account for a date range, ordered by account code. The opening balance is the
     * ledger before `$from`; accounts with neither an opening balance nor movement are left out.
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @return list<TrialBalanceRow>
     */
    public function trialBalance(string $companyId, string $from, string $to, ?string $accountId = null): array;
}
