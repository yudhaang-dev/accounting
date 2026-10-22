<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\RateType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RateType $resource
 */
class RateTypeResource extends JsonResource
{
    /**
     * @return array{id: string, code: string, name: string, is_active: bool, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'is_active' => $this->resource->is_active,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
