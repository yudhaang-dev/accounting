<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class HistoryImportData
{
    /** @param  bool  $replayed  the import existed already, nothing was posted */
    public function __construct(
        public string $id,
        public string $batchId,
        public string $sourceKey,
        public string $granularity,
        public int $journalCount,
        public string $createdAt,
        public bool $replayed = false,
    ) {}
}
