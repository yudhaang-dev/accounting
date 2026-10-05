<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The open items that come with an opening balance batch (SL-FR-10).
 */
final readonly class OpeningOpenItemBatch
{
    /**
     * @param  string  $ledgerStartDate  `YYYY-MM-DD`, the date of the balances and of the journal that carries them
     * @param  string|null  $sourceSystem  the source system whose party references the items use
     * @param  list<OpeningOpenItem>  $items
     */
    public function __construct(
        public string $companyId,
        public string $ledgerStartDate,
        public array $items,
        public ?int $importedBy = null,
        public ?string $sourceSystem = null,
    ) {}
}
