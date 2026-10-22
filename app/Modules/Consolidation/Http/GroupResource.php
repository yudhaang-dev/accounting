<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Group $resource
 */
class GroupResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, parent_company_id: string, presentation_currency: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'parent_company_id' => $this->resource->parent_company_id,
            'presentation_currency' => $this->resource->presentation_currency,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
