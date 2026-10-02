<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\RateType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RateType $resource
 */
class RateTypeResource extends JsonResource
{
    /**
     * @return array{id: string, key: string, label: string, max_age_days: int|null, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'max_age_days' => $this->resource->max_age_days,
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
