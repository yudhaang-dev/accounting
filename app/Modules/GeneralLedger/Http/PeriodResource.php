<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Period;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Period $resource
 */
class PeriodResource extends JsonResource
{
    /**
     * @return array{id: string, fiscal_year_id: string, number: int, name: string, start_date: string, end_date: string, status: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'fiscal_year_id' => $this->resource->fiscal_year_id,
            'number' => $this->resource->number,
            'name' => $this->resource->name,
            'start_date' => $this->resource->start_date->toDateString(),
            'end_date' => $this->resource->end_date->toDateString(),
            'status' => $this->resource->status->value,
        ];
    }
}
