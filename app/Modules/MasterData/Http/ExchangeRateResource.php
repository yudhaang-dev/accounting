<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ExchangeRate $resource
 */
class ExchangeRateResource extends JsonResource
{
    /**
     * @return array{id: string, rate_type_id: string, rate_type: string, currency: string, effective_date: string, rate: string, version: int, source: string, actor_type: string|null, actor_id: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'rate_type_id' => $this->resource->rate_type_id,
            'rate_type' => $this->resource->rateType->key,
            'currency' => $this->resource->currency,
            'effective_date' => $this->resource->effective_date->toDateString(),
            'rate' => ExchangeRate::format($this->resource->rate),
            'version' => $this->resource->version,
            'source' => $this->resource->source->value,
            'actor_type' => $this->resource->actor_type,
            'actor_id' => $this->resource->actor_id,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
