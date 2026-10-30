<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\PartyRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PartyRole $resource
 */
class PartyRoleResource extends JsonResource
{
    /**
     * @return array{id: string, key: string, label: string, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
