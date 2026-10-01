<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use App\Modules\GeneralLedger\Domain\FiscalYear;
use App\Modules\GeneralLedger\Domain\Period;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates fiscal years with their twelve monthly periods (GL-FR-03). The first year starts where the
 * caller says (on the first day of the calendar start month); every later year continues the day
 * after the previous one, so periods never leave a gap or overlap. No opening journal is made: the
 * opening balance of a period is the closing balance of the one before it.
 */
final class CreateFiscalYears
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @return list<FiscalYear> the created years, each with its periods loaded
     *
     * @throws ApiException MAPPING_REQUIRED when there is no fiscal calendar yet
     * @throws ValidationException
     */
    public function __invoke(?string $startDate, int $count = 1, ?string $name = null): array
    {
        return DB::transaction(function () use ($startDate, $count, $name): array {
            $calendar = FiscalCalendar::query()->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::MappingRequired, __('periods.calendar_required'));

            if ($name !== null && $count > 1) {
                throw ValidationException::withMessages(['name' => [__('periods.name_with_count')]]);
            }

            $next = $this->firstStart($calendar, $startDate);
            $years = [];

            for ($i = 0; $i < $count; $i++) {
                $years[] = $this->createYear($calendar, $next, $name);
                $next = $years[$i]->end_date->toImmutable()->addDay();
            }

            return $years;
        });
    }

    private function firstStart(FiscalCalendar $calendar, ?string $startDate): CarbonImmutable
    {
        $last = FiscalYear::query()->orderByDesc('end_date')->first();

        if ($last === null) {
            if ($startDate === null) {
                throw ValidationException::withMessages(['start_date' => [__('periods.start_date_required')]]);
            }

            $start = CarbonImmutable::parse($startDate)->startOfDay();

            if ($start->day !== 1 || $start->month !== $calendar->start_month) {
                throw ValidationException::withMessages(['start_date' => [__('periods.start_date_first_day', ['month' => $calendar->start_month])]]);
            }

            return $start;
        }

        $continues = $last->end_date->toImmutable()->addDay();

        if ($startDate !== null && ! $continues->isSameDay(CarbonImmutable::parse($startDate))) {
            throw ValidationException::withMessages(['start_date' => [__('periods.start_date_continue', ['date' => $continues->toDateString()])]]);
        }

        return $continues;
    }

    private function createYear(FiscalCalendar $calendar, CarbonImmutable $start, ?string $name): FiscalYear
    {
        $end = $start->addMonthsNoOverflow(12)->subDay();
        $name ??= $calendar->start_month === 1 ? "FY{$start->year}" : "FY{$start->year}/{$end->year}";

        if (FiscalYear::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => [__('periods.name_taken', ['name' => $name])]]);
        }

        $year = FiscalYear::create([
            'calendar_id' => $calendar->id,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
        ]);

        for ($i = 0; $i < 12; $i++) {
            $periodStart = $start->addMonthsNoOverflow($i);

            Period::create([
                'fiscal_year_id' => $year->id,
                'number' => $i + 1,
                'name' => $periodStart->format('Y-m'),
                'start_date' => $periodStart,
                'end_date' => $periodStart->endOfMonth(),
            ]);
        }

        $this->audit->record(
            'fiscal_year.created',
            "Fiscal year {$year->name} created.",
            $year,
            after: ['name' => $year->name, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'periods' => 12],
            companyId: $year->company_id,
        );

        return $year->load('periods');
    }
}
