<?php

namespace App\Modules\Planning\Contracts;

final readonly class PlanVersionData
{
    /**
     * @param  string  $status  `draft`, `published` or `archived`
     * @param  string  $startsOn  `YYYY-MM-DD`, the first day of the period of the plan
     * @param  string  $endsOn  `YYYY-MM-DD`, the last day of the period of the plan
     * @param  string|null  $scopeDimensionValueId  the dimension value the plan is measured against; null is the whole company
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $startsOn,
        public string $endsOn,
        public ?string $scopeDimensionValueId,
    ) {}
}
