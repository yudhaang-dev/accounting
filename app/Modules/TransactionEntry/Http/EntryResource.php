<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\Entry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Entry $resource
 */
class EntryResource extends JsonResource
{
    /**
     * @return array{id: string, number: string, status: string, source: string, transaction_type_key: string, transaction_type_version: int, accounting_date: string, document_date: string|null, description: string, lines: list<array{account_id: string, debit: string, credit: string, description: string|null}>, created_by: int, journal_id: string|null, journal_number: string|null, posted_at: string|null, decisions: array<int, array{action: string, actor_id: int, reason: string|null, at: string}>|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'number' => $this->resource->number,
            'status' => $this->resource->status->value,
            'source' => $this->resource->source,
            'transaction_type_key' => $this->resource->transaction_type_key,
            'transaction_type_version' => $this->resource->transaction_type_version,
            'accounting_date' => $this->resource->accounting_date->toDateString(),
            'document_date' => $this->resource->document_date?->toDateString(),
            'description' => $this->resource->description,
            'lines' => $this->resource->lines,
            'created_by' => $this->resource->created_by,
            'journal_id' => $this->resource->journal_id,
            'journal_number' => $this->resource->journal_number,
            'posted_at' => $this->resource->posted_at?->toIso8601String(),
            'decisions' => $this->resource->relationLoaded('decisions')
                ? array_map(fn ($decision): array => [
                    'action' => $decision->action,
                    'actor_id' => $decision->actor_id,
                    'reason' => $decision->reason,
                    'at' => $decision->created_at->toIso8601String(),
                ], $this->resource->decisions->all())
                : null,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
