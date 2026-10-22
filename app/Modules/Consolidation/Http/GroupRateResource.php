<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\GroupRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GroupRate $resource
 */
class GroupRateResource extends JsonResource
{
    /**
     * @return array{id: string, rate_type_id: string, currency: string, effective_date: string, rate: string, version: int, source: string, created_by: int|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'rate_type_id' => $this->resource->rate_type_id,
            'currency' => $this->resource->currency,
            'effective_date' => $this->resource->effective_date->toDateString(),
            'rate' => (string) $this->resource->rate,
            'version' => $this->resource->version,
            'source' => $this->resource->source,
            'created_by' => $this->resource->created_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
