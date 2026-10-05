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

    /**
     * Movement per account and dimension set between two dates, closing journals left out, for the
     * accounts of the given classes; combinations that net to zero are left out. The realization that
     * a plan starts from (PL-FR-03).
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @param  list<AccountClass>  $classes
     * @return list<AccountMovementRow> ordered by account code, then dimension set
     */
    public function movementByDimensionSet(string $companyId, string $from, string $to, array $classes): array;

    /**
     * The lines of an account up to and including a date that did not come from the source given, oldest
     * first. They are what makes the ledger differ from a subledger that is the only source of the account
     * (SL-FR-09).
     *
     * @param  string  $asOf  `YYYY-MM-DD`
     * @return list<AccountLineRow>
     */
    public function linesNotFromSource(string $companyId, string $accountId, string $asOf, JournalSource $source): array;
}
