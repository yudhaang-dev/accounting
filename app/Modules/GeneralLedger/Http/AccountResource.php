<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Account $resource
 */
class AccountResource extends JsonResource
{
    /**
     * @return array{id: string, code: string, name: string, class: string, normal_balance: string, parent_id: string|null, is_leaf: bool, status: string, allowed_sources: list<string>, dimension_requirements: object, currency_restriction: string, restricted_currency: string|null, is_revaluable: bool, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'class' => $this->resource->class->value,
            'normal_balance' => $this->resource->normal_balance,
            'parent_id' => $this->resource->parent_id,
            'is_leaf' => ! ($this->resource->has_children ?? $this->resource->children()->exists()),
            'status' => $this->resource->status->value,
            'allowed_sources' => $this->resource->allowed_sources,
            'dimension_requirements' => (object) $this->resource->dimension_requirements,
            'currency_restriction' => $this->resource->currency_restriction->value,
            'restricted_currency' => $this->resource->restricted_currency,
            'is_revaluable' => $this->resource->is_revaluable,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
