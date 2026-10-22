<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PartyExternalRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Party $resource
 */
class PartyResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, is_customer: bool, is_supplier: bool, tax_id: string|null, posting_profile_id: string|null, counterparty_company_id: string|null, status: string, external_refs: array<int, array{source_system: string, external_ref: string}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'is_customer' => $this->resource->is_customer,
            'is_supplier' => $this->resource->is_supplier,
            'tax_id' => $this->resource->tax_id,
            'posting_profile_id' => $this->resource->posting_profile_id,
            'counterparty_company_id' => $this->resource->counterparty_company_id,
            'status' => $this->resource->status->value,
            'external_refs' => $this->resource->externalRefs
                ->map(fn (PartyExternalRef $ref) => ['source_system' => $ref->source_system, 'external_ref' => $ref->external_ref])
                ->values()
                ->all(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
