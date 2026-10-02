<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Currency $resource
 */
class IsoCurrencyResource extends JsonResource
{
    /**
     * @return array{code: string, name: string, minor_unit: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'minor_unit' => $this->resource->minor_unit,
        ];
    }
}
