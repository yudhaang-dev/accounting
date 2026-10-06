<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Domain\MovementClassification;
use App\Modules\Reporting\Domain\MovementClassificationVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read MovementClassification $resource
 */
class MovementClassificationResource extends JsonResource
{
    private ?MovementClassificationVersion $version = null;

    /** The classification with the rows and rules of one of its versions. */
    public static function withVersion(MovementClassification $classification, MovementClassificationVersion $version): self
    {
        $resource = new self($classification);
        $resource->version = $version;

        return $resource;
    }

    /**
     * @return array{id: string, key: string, name: string, current_version: int, lock_version: int, version: int|null, structure: array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'current_version' => $this->resource->current_version,
            'lock_version' => $this->resource->lock_version,
            'version' => $this->version?->version,
            'structure' => $this->version?->structure,
        ];
    }
}
