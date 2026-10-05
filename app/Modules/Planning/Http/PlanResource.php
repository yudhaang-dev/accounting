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
     * @return array{id: string, name: string, fiscal_year_id: string, description: string|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'fiscal_year_id' => $this->resource->fiscal_year_id,
            'description' => $this->resource->description,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
