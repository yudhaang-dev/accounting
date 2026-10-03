<?php

namespace App\Orchestration\FinancialEvents\Http;

use App\Modules\TransactionEntry\Contracts\FinancialEventChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FinancialEventChange $resource
 */
class FinancialEventChangeResource extends JsonResource
{
    /**
     * @return array{cursor: string, event_id: string, action: string, status: string, journal_id: string|null, journal_number: string|null, occurred_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'cursor' => $this->resource->cursor,
            'event_id' => $this->resource->eventId,
            'action' => $this->resource->action,
            'status' => $this->resource->status,
            'journal_id' => $this->resource->journalId,
            'journal_number' => $this->resource->journalNumber,
            'occurred_at' => $this->resource->occurredAt,
        ];
    }
}
