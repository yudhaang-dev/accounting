<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\CompanyCurrency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A currency as the company uses it. `is_functional` is set by the controller from the company profile.
 *
 * @property-read CompanyCurrency $resource
 */
class CurrencyResource extends JsonResource
{
    /**
     * @return array{code: string, name: string, minor_unit: int, scale: int, rounding_mode: string, is_functional: bool, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->currency,
            'name' => $this->resource->reference->name,
            'minor_unit' => $this->resource->reference->minor_unit,
            'scale' => $this->resource->scale,
            'rounding_mode' => $this->resource->rounding_mode->value,
            'is_functional' => (bool) $this->resource->getAttribute('is_functional'),
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
