<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One journal line of the general ledger report (RP-FR-06) with the journal it belongs to. Amounts
 * are decimal strings: `debit` and `credit` in the functional currency, `transactionAmount` in
 * `currency` at `exchangeRate`.
 */
final readonly class LedgerLineRow
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`
     * @param  array<string, mixed>|null  $sourceReferences
     */
    public function __construct(
        public string $journalId,
        public string $journalNumber,
        public ?string $entryId,
        public string $accountId,
        public string $accountingDate,
        public JournalSource $source,
        public ?string $transactionTypeKey,
        public ?array $sourceReferences,
        public string $debit,
        public string $credit,
        public string $currency,
        public string $transactionAmount,
        public string $exchangeRate,
        public ?string $description,
    ) {}
}
