<?php

namespace App\Orchestration\Consolidation\Http;

use App\Modules\Consolidation\Contracts\GroupData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GroupData $resource
 */
class GroupDataResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, parent_company_id: string, presentation_currency: string, package_policy: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'parent_company_id' => $this->resource->parentCompanyId,
            'presentation_currency' => $this->resource->presentationCurrency,
            'package_policy' => $this->resource->packagePolicy,
            'lock_version' => $this->resource->lockVersion,
        ];
    }
}
