<?php

namespace App\Orchestration\Company\Http;

use App\Modules\MasterData\Contracts\CompanyData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CompanyData $resource
 */
class ActivatedCompanyResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, legal_name: string|null, tax_id: string|null, functional_currency: string, timezone: string, default_locale: string, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'legal_name' => $this->resource->legalName,
            'tax_id' => $this->resource->taxId,
            'functional_currency' => $this->resource->functionalCurrency,
            'timezone' => $this->resource->timezone,
            'default_locale' => $this->resource->defaultLocale,
            'status' => $this->resource->status,
            'lock_version' => $this->resource->lockVersion,
        ];
    }
}
