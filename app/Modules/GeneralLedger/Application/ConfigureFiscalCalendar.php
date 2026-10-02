<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\CalendarPattern;
use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use App\Modules\GeneralLedger\Domain\FiscalYear;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets up and changes the fiscal calendar of the current company (GL-FR-03). The calendar can only
 * change while no fiscal year has been created from it. Only a monthly calendar has a start month;
 * 4-4-5 and free calendars start on the date of their first fiscal year.
 */
final class ConfigureFiscalCalendar
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{pattern: string, start_month?: int|null}  $data
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the company already has a calendar
     * @throws ValidationException
     */
    public function create(array $data): FiscalCalendar
    {
        return DB::transaction(function () use ($data): FiscalCalendar {
            if (FiscalCalendar::query()->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('periods.calendar_exists'));
            }

            $values = $this->consistent(CalendarPattern::from($data['pattern']), $data['start_month'] ?? null);

            $calendar = FiscalCalendar::create($values)->refresh();

            $this->audit->record(
                'fiscal_calendar.created',
                'Fiscal calendar set up.',
                $calendar,
                after: $this->snapshot($calendar),
                companyId: $calendar->company_id,
            );

            return $calendar;
        });
    }

    /**
     * @param  array{pattern?: string, start_month?: int|null}  $data
     *
     * @throws ApiException VERSION_CONFLICT, or INVALID_STATE_TRANSITION when fiscal years exist and the change is real
     * @throws ValidationException
     */
    public function update(array $data, int $expectedVersion): FiscalCalendar
    {
        return DB::transaction(function () use ($data, $expectedVersion): FiscalCalendar {
            $calendar = FiscalCalendar::query()->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($calendar);
            $pattern = isset($data['pattern']) ? CalendarPattern::from($data['pattern']) : $calendar->pattern;
            // Leaving `monthly` drops the start month; entering it needs one.
            $startMonth = array_key_exists('start_month', $data) ? $data['start_month'] : ($pattern === $calendar->pattern ? $calendar->start_month : null);
            $changes = $this->consistent($pattern, $startMonth);

            $requested = ['pattern' => $changes['pattern']->value, 'start_month' => $changes['start_month']];

            if ($requested !== $before && FiscalYear::query()->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('periods.calendar_locked'));
            }

            $calendar->updateLocked($changes, $expectedVersion);

            $after = $this->snapshot($calendar);

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

    /**
     * @return array{pattern: CalendarPattern, start_month: int|null}
     *
     * @throws ValidationException
     */
    private function consistent(CalendarPattern $pattern, ?int $startMonth): array
    {
        if ($pattern === CalendarPattern::Monthly && $startMonth === null) {
            throw ValidationException::withMessages(['start_month' => [__('periods.start_month_required')]]);
        }

        if ($pattern !== CalendarPattern::Monthly && $startMonth !== null) {
            throw ValidationException::withMessages(['start_month' => [__('periods.start_month_monthly_only')]]);
        }

        return ['pattern' => $pattern, 'start_month' => $startMonth];
    }

    /** @return array{pattern: string, start_month: int|null} */
    private function snapshot(FiscalCalendar $calendar): array
    {
        return ['pattern' => $calendar->pattern->value, 'start_month' => $calendar->start_month];
    }
}
