<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\OpenItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read OpenItem $resource
 */
class OpenItemResource extends JsonResource
{
    /**
     * @return array{id: string, document_id: string, party_id: string, control_role: string, kind: string, side: string, document_number: string, document_date: string, due_date: string, currency: string, original_amount: string, remaining_amount: string, original_functional: string, remaining_functional: string, exchange_rate: string, status: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'document_id' => $this->resource->document_id,
            'party_id' => $this->resource->party_id,
            'control_role' => $this->resource->control_role->value,
            'kind' => $this->resource->kind->value,
            'side' => $this->resource->side,
            'document_number' => $this->resource->document_number,
            'document_date' => $this->resource->document_date->toDateString(),
            'due_date' => $this->resource->due_date->toDateString(),
            'currency' => $this->resource->currency,
            'original_amount' => (string) $this->resource->original_amount,
            'remaining_amount' => (string) $this->resource->remaining_amount,
            'original_functional' => (string) $this->resource->original_functional,
            'remaining_functional' => (string) $this->resource->remaining_functional,
            'exchange_rate' => (string) $this->resource->exchange_rate,
            'status' => $this->resource->status->value,
        ];
    }
}
