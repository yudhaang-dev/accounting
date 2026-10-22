<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BankStatementLine $resource
 */
class BankStatementLineResource extends JsonResource
{
    /**
     * @return array{id: string, statement_id: string, cash_account_id: string, line_number: int, transaction_date: string, description: string|null, reference: string|null, direction: string, amount: string, cash_transaction_id: string|null, matched_at: string|null, matched_by: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'statement_id' => $this->resource->statement_id,
            'cash_account_id' => $this->resource->cash_account_id,
            'line_number' => $this->resource->line_number,
            'transaction_date' => $this->resource->transaction_date->toDateString(),
            'description' => $this->resource->description,
            'reference' => $this->resource->reference,
            'direction' => $this->resource->direction->value,
            'amount' => (string) $this->resource->amount,
            'cash_transaction_id' => $this->resource->cash_transaction_id,
            'matched_at' => $this->resource->matched_at?->toIso8601String(),
            'matched_by' => $this->resource->matched_by,
        ];
    }
}
