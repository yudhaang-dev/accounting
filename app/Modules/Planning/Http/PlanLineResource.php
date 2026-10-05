<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Domain\PlanLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PlanLine $resource
 */
class PlanLineResource extends JsonResource
{
    /**
     * @return array{id: string, plan_version_id: string, period_id: string, account_id: string, dimension_set_id: string, dimensions: array<string, string>, amount: string, note: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'plan_version_id' => $this->resource->plan_version_id,
            'period_id' => $this->resource->period_id,
            'account_id' => $this->resource->account_id,
            'dimension_set_id' => $this->resource->dimension_set_id,
            // Dimension type key => dimension value ID.
            'dimensions' => $this->resource->getAttribute('dimensions') ?? [],
            'amount' => (string) $this->resource->amount,
            'note' => $this->resource->note,
        ];
    }
}
