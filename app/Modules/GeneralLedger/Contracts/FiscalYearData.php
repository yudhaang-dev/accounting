<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class FiscalYearData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $startDate,
        public string $endDate,
    ) {}
}
