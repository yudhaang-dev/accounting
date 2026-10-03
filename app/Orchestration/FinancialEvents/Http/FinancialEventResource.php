<?php

namespace App\Orchestration\FinancialEvents\Http;

use App\Modules\TransactionEntry\Contracts\FinancialEventResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FinancialEventResult $resource
 */
class FinancialEventResource extends JsonResource
{
    /**
     * @return array{event_id: string, status: string, journal_id: string|null, journal_number: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->resource->eventId,
            // The status of the entry the event became; the journal is there once it is posted.
            'status' => $this->resource->status,
            'journal_id' => $this->resource->journalId,
            'journal_number' => $this->resource->journalNumber,
        ];
    }
}
