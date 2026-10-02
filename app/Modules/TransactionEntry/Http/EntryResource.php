<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\Entry;
use App\Platform\Api\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Entry $resource
 */
class EntryResource extends JsonResource
{
    /**
     * @return array{id: string, number: string, status: string, source: string, transaction_type_key: string, transaction_type_version: int, accounting_date: string, document_date: string|null, description: string, lines: list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>, currency: string|null, exchange_rate: string|null, applied_rate: string|null}>, values: array<string, mixed>|null, currency: string|null, exchange_rate: string|null, created_by: int, journal_id: string|null, journal_number: string|null, posted_at: string|null, decisions: list<array{action: string, actor_id: int, reason: string|null, at: string}>|null, lock_version: int}
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
            'lines' => $this->lines(),
            'values' => $this->resource->field_values === null ? null : JsonMap::ofMixed($this->resource->field_values),
            'currency' => $this->resource->currency,
            'exchange_rate' => $this->resource->exchange_rate,
            'created_by' => $this->resource->created_by,
            'journal_id' => $this->resource->journal_id,
            'journal_number' => $this->resource->journal_number,
            'posted_at' => $this->resource->posted_at?->toIso8601String(),
            'decisions' => $this->resource->relationLoaded('decisions') ? $this->decisions() : null,
            'lock_version' => $this->resource->lock_version,
        ];
    }

    /**
     * @return list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>, currency: string|null, exchange_rate: string|null, applied_rate: string|null}>
     */
    private function lines(): array
    {
        $lines = [];

        foreach ($this->resource->lines as $line) {
            $lines[] = [
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'dimensions' => JsonMap::of($line['dimensions'] ?? []),
                'currency' => $line['currency'] ?? null,
                'exchange_rate' => $line['exchange_rate'] ?? null,
                'applied_rate' => $line['applied_rate'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{action: string, actor_id: int, reason: string|null, at: string}>
     */
    private function decisions(): array
    {
        $decisions = [];

        foreach ($this->resource->decisions as $decision) {
            $decisions[] = [
                'action' => $decision->action,
                'actor_id' => (int) $decision->actor_id,
                'reason' => $decision->reason === null ? null : (string) $decision->reason,
                'at' => $decision->created_at->toIso8601String(),
            ];
        }

        return $decisions;
    }
}
