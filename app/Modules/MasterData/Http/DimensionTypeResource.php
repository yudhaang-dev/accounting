<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\DimensionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read DimensionType $resource
 */
class DimensionTypeResource extends JsonResource
{
    /**
     * @return array{id: string, key: string, label: string, is_hierarchical: bool, leaf_only: bool, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'is_hierarchical' => $this->resource->is_hierarchical,
            'leaf_only' => $this->resource->leaf_only,
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
