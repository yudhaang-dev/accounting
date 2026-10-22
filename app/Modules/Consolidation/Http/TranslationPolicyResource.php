<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\TranslationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TranslationPolicy $resource
 */
class TranslationPolicyResource extends JsonResource
{
    /**
     * @return array{version: int, effective_date: string, closing_rate_type_id: string, average_rate_type_id: string, historical_rate_type_id: string, difference_account_id: string, created_by: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->resource->version,
            'effective_date' => $this->resource->effective_date->toDateString(),
            'closing_rate_type_id' => $this->resource->closing_rate_type_id,
            'average_rate_type_id' => $this->resource->average_rate_type_id,
            'historical_rate_type_id' => $this->resource->historical_rate_type_id,
            'difference_account_id' => $this->resource->difference_account_id,
            'created_by' => $this->resource->created_by,
        ];
    }
}
