<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * A posted journal as another module needs to know it before asking for its reversal (GL-FR-06).
 */
final readonly class JournalData
{
    /**
     * @param  string  $accountingDate  `YYYY-MM-DD`
     * @param  string  $total  sum of the debits in the functional currency, as a decimal string
     * @param  bool  $reversed  a reversal of the journal exists
     * @param  bool  $isReversal  the journal is itself the reversal of another
     * @param  bool  $withinScope  every line is inside the data scope of the caller
     */
    public function __construct(
        public string $id,
        public string $number,
        public string $accountingDate,
        public JournalSource $source,
        public ?string $transactionTypeKey,
        public string $total,
        public bool $reversed,
        public bool $isReversal,
        public bool $withinScope,
    ) {}
}
