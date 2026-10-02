<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\DimensionValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read DimensionValue $resource
 */
class DimensionValueResource extends JsonResource
{
    /**
     * @return array{id: string, dimension_type_id: string, code: string, name: string, parent_id: string|null, is_leaf: bool, valid_from: string|null, valid_to: string|null, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'dimension_type_id' => $this->resource->dimension_type_id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'parent_id' => $this->resource->parent_id,
            'is_leaf' => ! ($this->resource->has_children ?? $this->resource->children()->exists()),
            'valid_from' => $this->resource->valid_from?->toDateString(),
            'valid_to' => $this->resource->valid_to?->toDateString(),
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
