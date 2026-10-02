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
     * @return array{id: string, key: string, label: string, versions: list<array{version: int, mode: string, status: string, fields: list<array<string, mixed>>|null}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'versions' => $this->versions(),
            'lock_version' => $this->resource->lock_version,
        ];
    }

    /**
     * @return list<array{version: int, mode: string, status: string, fields: list<array<string, mixed>>|null}>
     */
    private function versions(): array
    {
        $versions = [];

        foreach ($this->resource->versions as $version) {
            $versions[] = [
                'version' => (int) $version->version,
                'mode' => $version->mode->value,
                'status' => $version->status->value,
                'fields' => $version->fields,
            ];
        }

        return $versions;
    }
}
