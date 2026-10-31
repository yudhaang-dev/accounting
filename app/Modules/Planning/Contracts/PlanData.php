<?php

namespace App\Modules\Planning\Contracts;

final readonly class PlanData
{
    /**
     * @param  string  $startsOn  `YYYY-MM-DD`
     * @param  string  $endsOn  `YYYY-MM-DD`
     * @param  string|null  $scopeDimensionValueId  the dimension value the plan is measured against; null is the whole company
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $objective,
        public string $startsOn,
        public string $endsOn,
        public ?string $scopeDimensionValueId,
    ) {}
}
