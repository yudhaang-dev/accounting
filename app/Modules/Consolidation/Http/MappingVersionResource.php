<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\MappingRow;
use App\Modules\Consolidation\Domain\MappingVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read MappingVersion $resource
 */
class MappingVersionResource extends JsonResource
{
    /**
     * @return array{version: int, effective_date: string, created_by: int|null, mappings: list<array{company_id: string, account_id: string, account_code: string, group_account_id: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->resource->version,
            'effective_date' => $this->resource->effective_date->toDateString(),
            'created_by' => $this->resource->created_by,
            'mappings' => array_values($this->resource->rows
                ->map(fn (MappingRow $row): array => [
                    'company_id' => $row->company_id,
                    'account_id' => $row->account_id,
                    'account_code' => $row->account_code,
                    'group_account_id' => $row->group_account_id,
                ])
                ->all()),
        ];
    }
}
