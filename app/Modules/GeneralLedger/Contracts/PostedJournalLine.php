<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * A line of a journal as it was stored (GL-FR-08): the functional amounts and the amount and rate in
 * the transaction currency. Lets the poster of a journal record what the ledger made of it, such as the
 * functional value of an open item, without reading ledger tables.
 */
final readonly class PostedJournalLine
{
    /**
     * @param  string  $debit  functional currency, as a decimal string
     * @param  string  $credit  functional currency, as a decimal string
     * @param  string  $currency  transaction currency; the functional currency for a line posted in it
     * @param  string  $transactionAmount  the debit or credit in `$currency`
     * @param  string  $exchangeRate  functional currency per 1 unit of `$currency`
     */
    public function __construct(
        public string $accountId,
        public string $debit,
        public string $credit,
        public string $currency,
        public string $transactionAmount,
        public string $exchangeRate,
    ) {}
}
