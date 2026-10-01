<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\CalendarPattern;
use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use App\Modules\GeneralLedger\Domain\FiscalYear;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Sets up and changes the fiscal calendar of the current company (GL-FR-03). The calendar can only
 * change while no fiscal year has been created from it.
 */
final class ConfigureFiscalCalendar
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{pattern: string, start_month: int}  $data
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the company already has a calendar
     */
    public function create(array $data): FiscalCalendar
    {
        return DB::transaction(function () use ($data): FiscalCalendar {
            if (FiscalCalendar::query()->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('periods.calendar_exists'));
            }

            $calendar = FiscalCalendar::create([
                'pattern' => CalendarPattern::from($data['pattern']),
                'start_month' => $data['start_month'],
            ])->refresh();

            $this->audit->record(
                'fiscal_calendar.created',
                'Fiscal calendar set up.',
                $calendar,
                after: ['pattern' => $calendar->pattern->value, 'start_month' => $calendar->start_month],
                companyId: $calendar->company_id,
            );

            return $calendar;
        });
    }

    /**
     * @param  array{pattern?: string, start_month?: int}  $data
     *
     * @throws ApiException VERSION_CONFLICT, or INVALID_STATE_TRANSITION when fiscal years exist and the change is real
     */
    public function update(array $data, int $expectedVersion): FiscalCalendar
    {
        return DB::transaction(function () use ($data, $expectedVersion): FiscalCalendar {
            $calendar = FiscalCalendar::query()->lockForUpdate()->firstOrFail();

            $changes = [];

            if (isset($data['pattern'])) {
                $changes['pattern'] = CalendarPattern::from($data['pattern']);
            }

            if (isset($data['start_month'])) {
                $changes['start_month'] = $data['start_month'];
            }

            $before = ['pattern' => $calendar->pattern->value, 'start_month' => $calendar->start_month];
            $requested = [...$before, ...$data];

            if ($requested !== $before && FiscalYear::query()->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('periods.calendar_locked'));
            }

            $calendar->updateLocked($changes, $expectedVersion);

            $after = ['pattern' => $calendar->pattern->value, 'start_month' => $calendar->start_month];

            if ($before !== $after) {
                $this->audit->record(
                    'fiscal_calendar.updated',
                    'Fiscal calendar changed.',
                    $calendar,
                    before: $before,
                    after: $after,
                    companyId: $calendar->company_id,
                );
            }

            return $calendar->refresh();
        });
    }
}
