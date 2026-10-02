<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\CalendarPattern;
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
 * Creates fiscal years with their periods (GL-FR-03), cut by the pattern of the company calendar:
 * twelve calendar months, twelve periods of 4, 4 and 5 weeks per quarter, or the periods the caller
 * lists. The first year starts where the caller says; every later year continues the day after the
 * previous one, so periods never leave a gap or overlap. No opening journal is made: the opening
 * balance of a period is the closing balance of the one before it.
 */
final class CreateFiscalYears
{
    /** Weeks of the twelve periods of a 4-4-5 year, 52 in all. */
    private const FOUR_FOUR_FIVE = [4, 4, 5, 4, 4, 5, 4, 4, 5, 4, 4, 5];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<array{name?: string|null, start_date: string, end_date: string}>  $periods  only for a free calendar, whose periods are given by the caller
     * @param  bool  $extraWeek  only for 4-4-5: a 53-week year, with the week added to the last period
     * @return list<FiscalYear> the created years, each with its periods loaded
     *
     * @throws ApiException MAPPING_REQUIRED when there is no fiscal calendar yet
     * @throws ValidationException
     */
    public function __invoke(?string $startDate, int $count = 1, ?string $name = null, array $periods = [], bool $extraWeek = false): array
    {
        return DB::transaction(function () use ($startDate, $count, $name, $periods, $extraWeek): array {
            $calendar = FiscalCalendar::query()->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::MappingRequired, __('periods.calendar_required'));

            $this->assertOptions($calendar->pattern, $count, $name, $periods, $extraWeek);

            $next = $this->firstStart($calendar, $startDate, $periods);
            $years = [];

            for ($i = 0; $i < $count; $i++) {
                $plan = match ($calendar->pattern) {
                    CalendarPattern::Monthly => $this->monthly($next),
                    CalendarPattern::FourFourFive => $this->fourFourFive($next, $extraWeek),
                    CalendarPattern::Free => $this->free($next, $periods),
                };

                $years[] = $this->createYear($calendar, $plan, $name);
                $next = $years[$i]->end_date->toImmutable()->addDay();
            }

            return $years;
        });
    }

    /**
     * @param  list<array{name?: string|null, start_date: string, end_date: string}>  $periods
     *
     * @throws ValidationException
     */
    private function assertOptions(CalendarPattern $pattern, int $count, ?string $name, array $periods, bool $extraWeek): void
    {
        $errors = [];

        if ($name !== null && $count > 1) {
            $errors['name'][] = __('periods.name_with_count');
        }

        if ($pattern === CalendarPattern::Free) {
            if ($periods === []) {
                $errors['periods'][] = __('periods.periods_required');
            }

            if ($count > 1) {
                $errors['count'][] = __('periods.free_single_year');
            }
        } elseif ($periods !== []) {
            $errors['periods'][] = __('periods.periods_only_free');
        }

        if ($extraWeek && ($pattern !== CalendarPattern::FourFourFive || $count > 1)) {
            $errors['extra_week'][] = __('periods.extra_week_only');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array{name?: string|null, start_date: string, end_date: string}>  $periods
     */
    private function firstStart(FiscalCalendar $calendar, ?string $startDate, array $periods): CarbonImmutable
    {
        // A free calendar says where the year starts through its first period.
        $startDate ??= $calendar->pattern === CalendarPattern::Free ? ($periods[0]['start_date'] ?? null) : null;
        $last = FiscalYear::query()->orderByDesc('end_date')->first();

        if ($last === null) {
            if ($startDate === null) {
                throw ValidationException::withMessages(['start_date' => [__('periods.start_date_required')]]);
            }

            $start = CarbonImmutable::parse($startDate)->startOfDay();

            if ($calendar->pattern === CalendarPattern::Monthly && ($start->day !== 1 || $start->month !== $calendar->start_month)) {
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

    /**
     * @return list<array{name: string|null, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function monthly(CarbonImmutable $start): array
    {
        $plan = [];

        for ($i = 0; $i < 12; $i++) {
            $periodStart = $start->addMonthsNoOverflow($i);
            $plan[] = ['name' => $periodStart->format('Y-m'), 'start' => $periodStart, 'end' => $periodStart->endOfMonth()];
        }

        return $plan;
    }

    /**
     * @return list<array{name: string|null, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function fourFourFive(CarbonImmutable $start, bool $extraWeek): array
    {
        $weeks = self::FOUR_FOUR_FIVE;

        if ($extraWeek) {
            $weeks[11]++;
        }

        $plan = [];
        $cursor = $start;

        foreach ($weeks as $length) {
            $end = $cursor->addWeeks($length)->subDay();
            $plan[] = ['name' => null, 'start' => $cursor, 'end' => $end];
            $cursor = $end->addDay();
        }

        return $plan;
    }

    /**
     * @param  list<array{name?: string|null, start_date: string, end_date: string}>  $periods
     * @return list<array{name: string|null, start: CarbonImmutable, end: CarbonImmutable}>
     *
     * @throws ValidationException
     */
    private function free(CarbonImmutable $start, array $periods): array
    {
        $plan = [];
        $expected = $start;
        $errors = [];

        // Validation hands the entries back in the order its rules touched them, not the order of the request.
        ksort($periods);

        foreach ($periods as $index => $period) {
            $from = CarbonImmutable::parse($period['start_date'])->startOfDay();
            $to = CarbonImmutable::parse($period['end_date'])->startOfDay();

            if ($to->lt($from)) {
                $errors["periods.{$index}.end_date"][] = __('periods.period_end_before_start');
            }

            if (! $from->isSameDay($expected)) {
                $errors["periods.{$index}.start_date"][] = __('periods.period_not_contiguous', ['date' => $expected->toDateString()]);
            }

            $plan[] = ['name' => $period['name'] ?? null, 'start' => $from, 'end' => $to];
            $expected = $to->addDay();
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $plan;
    }

    /**
     * @param  list<array{name: string|null, start: CarbonImmutable, end: CarbonImmutable}>  $plan
     */
    private function createYear(FiscalCalendar $calendar, array $plan, ?string $name): FiscalYear
    {
        $start = $plan[0]['start'];
        $end = $plan[count($plan) - 1]['end'];
        $name ??= $this->defaultName($calendar, $start, $end);

        if (FiscalYear::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => [__('periods.name_taken', ['name' => $name])]]);
        }

        $year = FiscalYear::create([
            'calendar_id' => $calendar->id,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
        ]);

        foreach ($plan as $index => $period) {
            Period::create([
                'fiscal_year_id' => $year->id,
                'number' => $index + 1,
                'name' => $period['name'] ?? sprintf('%s-P%02d', $name, $index + 1),
                'start_date' => $period['start'],
                'end_date' => $period['end'],
            ]);
        }

        $this->audit->record(
            'fiscal_year.created',
            "Fiscal year {$year->name} created.",
            $year,
            after: ['name' => $year->name, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'periods' => count($plan)],
            companyId: $year->company_id,
        );

        return $year->load('periods');
    }

    private function defaultName(FiscalCalendar $calendar, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $single = $calendar->pattern === CalendarPattern::Monthly ? $calendar->start_month === 1 : $start->year === $end->year;

        return $single ? "FY{$start->year}" : "FY{$start->year}/{$end->year}";
    }
}
