<?php

namespace App\Orchestration\Ledger\Http;

use App\Orchestration\Ledger\FiscalYearReadinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FiscalYearReadinessData $resource
 */
class FiscalYearClosingStatusResource extends JsonResource
{
    /**
     * @return array{closed: bool, closing: array<string, mixed>|null, ready: bool, checks: array{retained_earnings: array{ok: bool}, last_period: array{ok: bool, name: string|null, status: string|null}, unfinished_entries: array{ok: bool, count: int}}}
     */
    public function toArray(Request $request): array
    {
        $closing = $this->resource->closing;

        return [
            'closed' => $closing !== null,
            // The closing in force; null while the year is open, or after it was reopened.
            'closing' => $closing === null ? null : (new ClosingRunResource($closing))->resolve($request),
            // Whether every check passes, so the year can be closed now.
            'ready' => $this->resource->ready,
            'checks' => [
                'retained_earnings' => ['ok' => $this->resource->retainedEarningsMapped],
                'last_period' => ['ok' => $this->resource->lastPeriodAccepts, 'name' => $this->resource->lastPeriodName, 'status' => $this->resource->lastPeriodStatus],
                'unfinished_entries' => ['ok' => $this->resource->unfinishedEntries === 0, 'count' => $this->resource->unfinishedEntries],
            ],
        ];
    }
}
