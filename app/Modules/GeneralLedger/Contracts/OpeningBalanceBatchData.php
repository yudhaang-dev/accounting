<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class OpeningBalanceBatchData
{
    /** @param  bool  $replayed  the batch existed already, nothing was posted */
    public function __construct(
        public string $id,
        public string $sourceKey,
        public ?string $sourceSystem,
        public string $cutoverDate,
        public string $ledgerStartDate,
        public ?string $historyGranularity,
        public string $status,
        public string $journalId,
        public string $journalNumber,
        public int $lineCount,
        public string $createdAt,
        public bool $replayed = false,
    ) {}
}
