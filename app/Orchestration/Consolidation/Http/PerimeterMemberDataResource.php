<?php

namespace App\Orchestration\Consolidation\Http;

use App\Modules\Consolidation\Contracts\PerimeterMemberData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PerimeterMemberData $resource
 */
class PerimeterMemberDataResource extends JsonResource
{
    /**
     * @return array{id: string, company_id: string, status: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->companyId,
            'status' => $this->resource->status,
        ];
    }
}
