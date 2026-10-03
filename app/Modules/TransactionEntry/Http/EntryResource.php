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
     * @return array{id: string, number: string, status: string, source: string, transaction_type_key: string, transaction_type_version: int, accounting_date: string, document_date: string|null, description: string, lines: list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>, currency: string|null, exchange_rate: string|null, applied_rate: string|null}>, values: array<string, mixed>|null, currency: string|null, exchange_rate: string|null, approval: array{stages: list<string>, approved: int, auto_post: bool, separation_of_duties: bool}|null, reverses_journal_id: string|null, reason: string|null, replaces_entry_id: string|null, auto_reverse_on: string|null, auto_reversal_status: string|null, auto_reversal_error: string|null, auto_reversal_entry_id: string|null, created_by: int|null, source_system: string|null, source_transaction_id: string|null, journal_id: string|null, journal_number: string|null, posted_at: string|null, decisions: list<array{action: string, actor_id: int|null, client_id: string|null, reason: string|null, at: string}>|null, lock_version: int}
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
            'approval' => $this->approval(),
            'reverses_journal_id' => $this->resource->reverses_journal_id,
            'reason' => $this->resource->reason,
            'replaces_entry_id' => $this->resource->replaces_entry_id,
            'auto_reverse_on' => $this->resource->auto_reverse_on?->toDateString(),
            'auto_reversal_status' => $this->resource->auto_reversal_status,
            'auto_reversal_error' => $this->resource->auto_reversal_error,
            'auto_reversal_entry_id' => $this->resource->auto_reversal_entry_id,
            'created_by' => $this->resource->created_by,
            'source_system' => $this->resource->source_system,
            'source_transaction_id' => $this->resource->source_transaction_id,
            'journal_id' => $this->resource->journal_id,
            'journal_number' => $this->resource->journal_number,
            'posted_at' => $this->resource->posted_at?->toIso8601String(),
            'decisions' => $this->resource->relationLoaded('decisions') ? $this->decisions() : null,
            'lock_version' => $this->resource->lock_version,
        ];
    }

    /**
     * Where the entry stands in its approval: the permission each stage needs and how many are done.
     *
     * @return array{stages: list<string>, approved: int, auto_post: bool, separation_of_duties: bool}|null
     */
    private function approval(): ?array
    {
        $plan = $this->resource->approval;

        return $plan === null ? null : [
            'stages' => $plan['stages'],
            'approved' => count($plan['approvals']),
            'auto_post' => $plan['auto_post'],
            'separation_of_duties' => $plan['separation_of_duties'],
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
     * @return list<array{action: string, actor_id: int|null, client_id: string|null, reason: string|null, at: string}>
     */
    private function decisions(): array
    {
        $decisions = [];

        foreach ($this->resource->decisions as $decision) {
            $decisions[] = [
                'action' => $decision->action,
                'actor_id' => $decision->actor_id === null ? null : (int) $decision->actor_id,
                'client_id' => $decision->client_id,
                'reason' => $decision->reason === null ? null : (string) $decision->reason,
                'at' => $decision->created_at->toIso8601String(),
            ];
        }

        return $decisions;
    }
}
