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
     * @param  list<string>|null  $transactionTypeKeys  only lines of journals with one of these keys; null means all (RP-FR-04)
     * @param  JournalSource|null  $source  only lines of journals from this source; null means all (RP-FR-04)
     * @param  list<string>  $excludedTransactionTypeKeys  lines of journals with one of these keys are left out of the opening balance and the movement alike; see `SystemTransactionKeys` (RP-FR-07)
     * @return list<TrialBalanceRow>
     */
    public function trialBalance(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null, array $excludedTransactionTypeKeys = []): array;

    /**
     * Movement between two dates per account and transaction type key, the lines that classification
     * rules are matched against (RP-FR-03). Combinations without movement are left out. The other
     * parameters narrow the lines as in `trialBalance()`.
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @param  list<string>|null  $dimensionSetIds
     * @param  list<string>|null  $transactionTypeKeys
     * @return list<TypeMovementRow> ordered by account code, then key
     */
    public function movementByTransactionType(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array;

    /**
     * Movement per account and transaction type key of the lines that are not on one of the given
     * accounts, in journals that have at least one line on them: what a payment or receipt on a cash
     * account was for (RP-FR-09). The opening balance import and the closing journal are left out; the
     * other parameters narrow the counter lines as in `trialBalance()`.
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @param  list<string>  $accountIds  the accounts whose journals count
     * @param  list<string>|null  $dimensionSetIds
     * @param  list<string>|null  $transactionTypeKeys
     * @return list<TypeMovementRow> ordered by account code, then key
     */
    public function counterMovement(string $companyId, string $from, string $to, array $accountIds, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array;

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

    /**
     * The lines of `$from`..`$to`, oldest first (date, journal number, line number), for the general
     * ledger report (RP-FR-06). The other parameters narrow the lines as in `trialBalance()`.
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @param  list<string>|null  $dimensionSetIds
     * @param  list<string>|null  $transactionTypeKeys
     * @return list<LedgerLineRow>
     */
    public function accountLines(string $companyId, string $from, string $to, string $accountId, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array;

    /**
     * The balances of the lines that name another group company as counterparty, per counterparty and account,
     * ordered by counterparty, then account code (CO-FR-07). The opening balance is the ledger before `$from`.
     * Lines without a counterparty are not here, and combinations without balance or movement are left out.
     *
     * @param  string  $from  `YYYY-MM-DD`
     * @param  string  $to  `YYYY-MM-DD`
     * @return list<IntercompanyRow>
     */
    public function intercompanyBalances(string $companyId, string $from, string $to): array;
}
