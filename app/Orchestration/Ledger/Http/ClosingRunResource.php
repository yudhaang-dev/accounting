<?php

namespace App\Orchestration\Ledger\Http;

use App\Modules\GeneralLedger\Contracts\ClosingRunData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ClosingRunData $resource
 */
class ClosingRunResource extends JsonResource
{
    /**
     * @return array{id: string, fiscal_year_id: string, status: string, journal_id: string|null, journal_number: string|null, line_count: int, result: string, retained_earnings_mapping_version: int, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'fiscal_year_id' => $this->resource->fiscalYearId,
            'status' => $this->resource->status,
            // Null for a year without income or expense to close.
            'journal_id' => $this->resource->journalId,
            'journal_number' => $this->resource->journalNumber,
            'line_count' => $this->resource->lineCount,
            // Profit (positive) or loss (negative) of the year moved to retained earnings.
            'result' => $this->resource->result,
            'retained_earnings_mapping_version' => $this->resource->retainedEarningsMappingVersion,
            'created_at' => $this->resource->createdAt,
        ];
    }
}
