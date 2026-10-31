<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Domain\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Plan $resource
 */
class PlanResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, objective: string|null, starts_on: string, ends_on: string, scope_dimension_value_id: string|null, description: string|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'objective' => $this->resource->objective,
            'starts_on' => $this->resource->starts_on->toDateString(),
            'ends_on' => $this->resource->ends_on->toDateString(),
            'scope_dimension_value_id' => $this->resource->scope_dimension_value_id,
            'description' => $this->resource->description,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
