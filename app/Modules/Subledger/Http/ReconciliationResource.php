<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\ReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A balanced account is not kept, so it has no `id` (SL-FR-09).
 *
 * @property-read ReconciliationRun $resource
 */
class ReconciliationResource extends JsonResource
{
    /**
     * @return array{id: string|null, kind: string, account_id: string, account_code: string, currency: string, as_of: string, ledger_balance: string, detail_total: string, difference: string, status: string, causes: list<array{journal_id: string, number: string, accounting_date: string, source: string, debit: string, credit: string, description: string|null}>, waiver_reason: string|null, waived_by: int|null, waived_at: string|null, run_by: int|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kind' => $this->resource->kind,
            'account_id' => $this->resource->account_id,
            'account_code' => $this->resource->account_code,
            'currency' => $this->resource->currency,
            'as_of' => $this->resource->as_of->toDateString(),
            'ledger_balance' => (string) $this->resource->ledger_balance,
            'detail_total' => (string) $this->resource->detail_total,
            'difference' => (string) $this->resource->difference,
            'status' => $this->resource->status,
            'causes' => $this->resource->causes,
            'waiver_reason' => $this->resource->waiver_reason,
            'waived_by' => $this->resource->waived_by,
            'waived_at' => $this->resource->waived_at?->toIso8601String(),
            'run_by' => $this->resource->run_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
