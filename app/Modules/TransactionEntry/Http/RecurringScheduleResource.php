<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\RecurringSchedule;
use App\Platform\Api\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RecurringSchedule $resource
 */
class RecurringScheduleResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, transaction_type_key: string, transaction_type_version: int|null, template: array<string, mixed>, frequency: string, interval_count: int, start_date: string, end_date: string|null, initial_status: string, status: string, next_run_on: string|null, last_run_on: string|null, last_entry_id: string|null, last_error: string|null, last_error_at: string|null, created_by: int, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        $schedule = $this->resource;

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'transaction_type_key' => $schedule->transaction_type_key,
            'transaction_type_version' => $schedule->transaction_type_version,
            'template' => JsonMap::ofMixed($schedule->template),
            'frequency' => $schedule->frequency,
            'interval_count' => $schedule->interval_count,
            'start_date' => $schedule->start_date->toDateString(),
            'end_date' => $schedule->end_date?->toDateString(),
            'initial_status' => $schedule->initial_status,
            'status' => $schedule->status,
            'next_run_on' => $schedule->next_run_on?->toDateString(),
            'last_run_on' => $schedule->last_run_on?->toDateString(),
            'last_entry_id' => $schedule->last_entry_id,
            'last_error' => $schedule->last_error,
            'last_error_at' => $schedule->last_error_at?->toIso8601String(),
            'created_by' => $schedule->created_by,
            'lock_version' => $schedule->lock_version,
        ];
    }
}
