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
     * @return array{id: string, code: string, name: string, class: string, normal_balance: string, parent_id: string|null, is_leaf: bool, status: string, lock_version: int}
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
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
