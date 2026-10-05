<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\Allocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Allocation $resource
 */
class AllocationResource extends JsonResource
{
    /**
     * @return array{id: string, debit_item_id: string, credit_item_id: string, document_id: string|null, amount: string, functional_amount: string, allocation_date: string, status: string, created_by: int|null, cancelled_on: string|null, cancelled_by: int|null, cancel_reason: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'debit_item_id' => $this->resource->debit_item_id,
            'credit_item_id' => $this->resource->credit_item_id,
            'document_id' => $this->resource->document_id,
            'amount' => (string) $this->resource->amount,
            'functional_amount' => (string) $this->resource->functional_amount,
            'allocation_date' => $this->resource->allocation_date->toDateString(),
            'status' => $this->resource->status,
            'created_by' => $this->resource->created_by,
            'cancelled_on' => $this->resource->cancelled_on?->toDateString(),
            'cancelled_by' => $this->resource->cancelled_by,
            'cancel_reason' => $this->resource->cancel_reason,
        ];
    }
}
