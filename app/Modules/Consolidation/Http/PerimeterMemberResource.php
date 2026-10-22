<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\PerimeterMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PerimeterMember $resource
 */
class PerimeterMemberResource extends JsonResource
{
    /**
     * @return array{id: string, company_id: string, company_name: string, control_basis: string, treatment: string, status: string, effective_from: string, effective_to: string|null, proposed_by: int|null, approved_by: int|null, approved_at: string|null, ended_by: int|null, ended_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->company_id,
            // Set by the controller from the company directory; the ID stands in when the company is unknown.
            'company_name' => (string) ($this->resource->getAttribute('company_name') ?? $this->resource->company_id),
            'control_basis' => $this->resource->control_basis,
            'treatment' => $this->resource->treatment,
            'status' => $this->resource->status,
            'effective_from' => $this->resource->effective_from->toDateString(),
            'effective_to' => $this->resource->effective_to?->toDateString(),
            'proposed_by' => $this->resource->proposed_by,
            'approved_by' => $this->resource->approved_by,
            'approved_at' => $this->resource->approved_at?->toIso8601String(),
            'ended_by' => $this->resource->ended_by,
            'ended_at' => $this->resource->ended_at?->toIso8601String(),
        ];
    }
}
