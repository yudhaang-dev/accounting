<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\FiscalYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FiscalYear $resource
 */
class FiscalYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'start_date' => $this->resource->start_date->toDateString(),
            'end_date' => $this->resource->end_date->toDateString(),
            'period_count' => $this->resource->periods_count ?? ($this->resource->relationLoaded('periods') ? $this->resource->periods->count() : $this->resource->periods()->count()),
            'periods' => PeriodResource::collection($this->whenLoaded('periods')),
        ];
    }
}
