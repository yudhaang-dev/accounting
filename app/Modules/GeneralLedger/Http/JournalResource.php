<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\Journal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Journal $resource
 */
class JournalResource extends JsonResource
{
    /**
     * @return array{id: string, number: string, accounting_date: string, period_id: string, document_date: string|null, description: string, source: string, transaction_type_key: string|null, transaction_type_version: int|null, references: array<string, mixed>|null, reverses_journal_id: string|null, entry_id: string|null, posted_by: int|null, posted_at: string, lines: array<int, array{line_number: int, account_id: string, debit: string, credit: string, currency: string, transaction_amount: string, exchange_rate: string, dimension_set_id: string, description: string|null}>}
     */
    public function toArray(Request $request): array
    {
        $journal = $this->resource;

        return [
            'id' => $journal->id,
            'number' => $journal->number,
            'accounting_date' => $journal->accounting_date->toDateString(),
            'period_id' => $journal->period_id,
            'document_date' => $journal->document_date?->toDateString(),
            'description' => $journal->description,
            'source' => $journal->source,
            'transaction_type_key' => $journal->transaction_type_key,
            'transaction_type_version' => $journal->transaction_type_version,
            'references' => $journal->source_references,
            'reverses_journal_id' => $journal->reverses_journal_id,
            'entry_id' => $journal->entry_id,
            'posted_by' => $journal->posted_by,
            'posted_at' => $journal->posted_at->toIso8601String(),
            'lines' => array_map(fn ($line): array => [
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'debit' => $this->amount($line->debit),
                'credit' => $this->amount($line->credit),
                'currency' => $line->currency,
                'transaction_amount' => $this->amount($line->transaction_amount),
                'exchange_rate' => (string) $line->exchange_rate,
                'dimension_set_id' => $line->dimension_set_id,
                'description' => $line->description,
            ], $journal->lines->all()),
        ];
    }

    private function amount(BigDecimal $value): string
    {
        return (string) $value->toScale(max(0, (int) config('ledger.default_scale')), RoundingMode::HalfUp);
    }
}
