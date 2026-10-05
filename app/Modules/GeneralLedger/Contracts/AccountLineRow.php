<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * One journal line of an account, with the journal it belongs to (SL-FR-09). Amounts are decimal strings in
 * the functional currency.
 */
final readonly class AccountLineRow
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`
     */
    public function __construct(
        public string $journalId,
        public string $journalNumber,
        public string $accountingDate,
        public JournalSource $source,
        public string $debit,
        public string $credit,
        public ?string $description,
    ) {}
}
