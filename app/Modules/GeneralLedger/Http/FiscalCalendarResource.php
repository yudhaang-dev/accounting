<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FiscalCalendar $resource
 */
class FiscalCalendarResource extends JsonResource
{
    /**
     * @return array{id: string, pattern: string, start_month: int, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'pattern' => $this->resource->pattern->value,
            'start_month' => $this->resource->start_month,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
