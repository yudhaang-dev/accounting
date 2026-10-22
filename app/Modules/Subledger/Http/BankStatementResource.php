<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\BankStatement;
use App\Modules\Subledger\Domain\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BankStatement $resource
 */
class BankStatementResource extends JsonResource
{
    /**
     * @return array{id: string, cash_account_id: string, file_name: string|null, period_from: string, period_to: string, closing_date: string, closing_balance: string, line_count: int, matched_count: int, imported_by: int|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'cash_account_id' => $this->resource->cash_account_id,
            'file_name' => $this->resource->file_name,
            'period_from' => $this->resource->period_from->toDateString(),
            'period_to' => $this->resource->period_to->toDateString(),
            'closing_date' => $this->resource->closing_date->toDateString(),
            'closing_balance' => (string) $this->resource->closing_balance,
            'line_count' => $this->resource->line_count,
            'matched_count' => BankStatementLine::query()->where('statement_id', $this->resource->id)->whereNotNull('cash_transaction_id')->count(),
            'imported_by' => $this->resource->imported_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
