<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Contracts\WorkspaceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read WorkspaceData $resource
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, company_id: string, company_name: string, permissions: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'company_id' => $this->resource->companyId,
            'company_name' => $this->resource->companyName,
            'permissions' => $this->resource->permissions,
        ];
    }
}
