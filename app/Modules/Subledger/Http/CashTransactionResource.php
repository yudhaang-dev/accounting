<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CashTransaction $resource
 */
class CashTransactionResource extends JsonResource
{
    /**
     * @return array{id: string, cash_account_id: string, direction: string, amount: string, currency: string, transaction_date: string, reference: string|null, document_id: string|null, journal_id: string|null, cleared: bool, cleared_on: string|null, cleared_by: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'cash_account_id' => $this->resource->cash_account_id,
            'direction' => $this->resource->direction->value,
            'amount' => (string) $this->resource->amount,
            'currency' => $this->resource->currency,
            'transaction_date' => $this->resource->transaction_date->toDateString(),
            'reference' => $this->resource->reference,
            'document_id' => $this->resource->document_id,
            'journal_id' => $this->resource->journal_id,
            'cleared' => $this->resource->isCleared(),
            'cleared_on' => $this->resource->cleared_on?->toDateString(),
            'cleared_by' => $this->resource->cleared_by,
        ];
    }
}
