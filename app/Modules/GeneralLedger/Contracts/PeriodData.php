<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class PeriodData
{
    /** @param  string  $status  `open` or `closed` */
    public function __construct(
        public string $id,
        public string $fiscalYearId,
        public string $name,
        public string $startDate,
        public string $endDate,
        public string $status,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
