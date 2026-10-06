<?php

namespace App\Modules\Planning\Contracts;

final readonly class PlanVersionData
{
    /** @param  string  $status  `draft`, `published` or `archived` */
    public function __construct(
        public string $id,
        public string $fiscalYearId,
        public string $name,
        public string $status,
    ) {}
}
