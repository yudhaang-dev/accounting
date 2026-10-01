<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class PostedJournal
{
    /** @param  bool  $replayed  true when the entry had been posted before and the existing journal is returned */
    public function __construct(
        public string $id,
        public string $number,
        public string $accountingDate,
        public string $periodId,
        public bool $replayed = false,
    ) {}
}
