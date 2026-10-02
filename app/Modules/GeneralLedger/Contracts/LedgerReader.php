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
     * @param  list<string>|null  $dimensionSetIds  only lines in these dimension sets; null means all (MD-FR-05)
     * @return list<TrialBalanceRow>
     */
    public function trialBalance(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null): array;

    /**
     * Balance of each account per transaction currency up to and including a date, ordered by account
     * code and currency (GL-FR-08). Combinations without movement are left out; the functional
     * currency is one of the currencies like any other.
     *
     * @param  string  $asOf  `YYYY-MM-DD`
     * @param  list<string>|null  $dimensionSetIds  only lines in these dimension sets; null means all (MD-FR-05)
     * @return list<CurrencyBalanceRow>
     */
    public function currencyBalances(string $companyId, string $asOf, ?string $accountId = null, ?array $dimensionSetIds = null): array;
}
