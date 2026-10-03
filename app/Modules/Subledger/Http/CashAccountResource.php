<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\CashAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CashAccount $resource
 */
class CashAccountResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, kind: string, bank_name: string|null, account_number: string|null, currency: string, account_id: string, status: string, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'kind' => $this->resource->kind->value,
            'bank_name' => $this->resource->bank_name,
            'account_number' => $this->resource->account_number,
            'currency' => $this->resource->currency,
            'account_id' => $this->resource->account_id,
            'status' => $this->resource->status->value,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
