<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Domain\PlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PlanVersion $resource
 */
class PlanVersionResource extends JsonResource
{
    /**
     * @return array{id: string, plan_id: string, name: string, status: string, origin_version_id: string|null, published_by: int|null, published_at: string|null, lines_count: int, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'plan_id' => $this->resource->plan_id,
            'name' => $this->resource->name,
            'status' => $this->resource->status->value,
            'origin_version_id' => $this->resource->origin_version_id,
            'published_by' => $this->resource->published_by,
            'published_at' => $this->resource->published_at?->toIso8601String(),
            'lines_count' => $this->resource->lines()->count(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
