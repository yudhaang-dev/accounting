<?php

namespace App\Orchestration\Ledger\Http;

use App\Modules\GeneralLedger\Contracts\HistoryImportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read HistoryImportData $resource
 */
class HistoryImportResource extends JsonResource
{
    /**
     * @return array{id: string, batch_id: string, source_key: string, granularity: string, journal_count: int, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'batch_id' => $this->resource->batchId,
            'source_key' => $this->resource->sourceKey,
            'granularity' => $this->resource->granularity,
            'journal_count' => $this->resource->journalCount,
            'created_at' => $this->resource->createdAt,
        ];
    }
}
