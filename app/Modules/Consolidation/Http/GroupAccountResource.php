<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\GroupAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GroupAccount $resource
 */
class GroupAccountResource extends JsonResource
{
    /**
     * @return array{id: string, code: string, name: string, class: string, is_active: bool, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'class' => $this->resource->class,
            'is_active' => $this->resource->is_active,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
