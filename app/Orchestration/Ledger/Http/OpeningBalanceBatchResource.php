<?php

namespace App\Orchestration\Ledger\Http;

use App\Modules\GeneralLedger\Contracts\OpeningBalanceBatchData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read OpeningBalanceBatchData $resource
 */
class OpeningBalanceBatchResource extends JsonResource
{
    /**
     * @return array{id: string, source_key: string, source_system: string|null, cutover_date: string, ledger_start_date: string, history_granularity: string|null, status: string, journal_id: string, journal_number: string, line_count: int, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'source_key' => $this->resource->sourceKey,
            'source_system' => $this->resource->sourceSystem,
            'cutover_date' => $this->resource->cutoverDate,
            'ledger_start_date' => $this->resource->ledgerStartDate,
            // How the history between the two dates was imported; null until it is (GL-FR-11).
            'history_granularity' => $this->resource->historyGranularity,
            'status' => $this->resource->status,
            'journal_id' => $this->resource->journalId,
            'journal_number' => $this->resource->journalNumber,
            'line_count' => $this->resource->lineCount,
            'created_at' => $this->resource->createdAt,
        ];
    }
}
