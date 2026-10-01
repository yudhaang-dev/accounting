<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\TransactionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TransactionType $resource
 */
class TransactionTypeResource extends JsonResource
{
    /**
     * @return array{id: string, key: string, label: string, versions: array<int, array{version: int, mode: string, status: string}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'versions' => array_map(fn ($version): array => [
                'version' => $version->version,
                'mode' => $version->mode->value,
                'status' => $version->status->value,
            ], $this->resource->versions->all()),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
