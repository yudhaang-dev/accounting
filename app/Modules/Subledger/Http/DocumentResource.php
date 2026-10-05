<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Document $resource
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array{id: string, kind: string, control_role: string, party_id: string, cash_account_id: string|null, journal_id: string, entry_id: string|null, document_number: string, document_date: string, due_date: string, accounting_date: string, currency: string, amount: string, functional_amount: string, exchange_rate: string, description: string, is_opening: bool, reversal_journal_id: string|null, reversal_date: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kind' => $this->resource->kind->value,
            'control_role' => $this->resource->control_role->value,
            'party_id' => $this->resource->party_id,
            'cash_account_id' => $this->resource->cash_account_id,
            'journal_id' => $this->resource->journal_id,
            'entry_id' => $this->resource->entry_id,
            'document_number' => $this->resource->document_number,
            'document_date' => $this->resource->document_date->toDateString(),
            'due_date' => $this->resource->due_date->toDateString(),
            'accounting_date' => $this->resource->accounting_date->toDateString(),
            'currency' => $this->resource->currency,
            'amount' => (string) $this->resource->amount,
            'functional_amount' => (string) $this->resource->functional_amount,
            'exchange_rate' => (string) $this->resource->exchange_rate,
            'description' => $this->resource->description,
            // An open item of the conversion date, recorded with the opening balance journal.
            'is_opening' => $this->resource->is_opening,
            'reversal_journal_id' => $this->resource->reversal?->journal_id,
            'reversal_date' => $this->resource->reversal?->reversal_date->toDateString(),
        ];
    }
}
