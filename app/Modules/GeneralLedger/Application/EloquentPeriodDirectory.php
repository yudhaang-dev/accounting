<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\PeriodData;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use App\Modules\GeneralLedger\Domain\FiscalYear;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\GeneralLedger\Domain\PeriodStatus;
use App\Support\Company\CompanyScope;
use DateTimeInterface;

/**
 * Reads periods for the given company explicitly, independent of the request company context.
 */
final class EloquentPeriodDirectory implements PeriodDirectory
{
    public function forDate(string $companyId, DateTimeInterface|string $date): ?PeriodData
    {
        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        $period = Period::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $day)
            ->where('end_date', '>=', $day)
            ->first();

        if ($period === null) {
            return null;
        }

        return new PeriodData(
            $period->id,
            $period->fiscal_year_id,
            $period->name,
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
            $period->status->value,
        );
    }

    public function setupProblems(string $companyId): array
    {
        if (! FiscalCalendar::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->exists()) {
            return ['calendar_missing'];
        }

        if (! FiscalYear::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->exists()) {
            return ['no_fiscal_year'];
        }

        $hasOpenPeriod = Period::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('status', PeriodStatus::Open->value)
            ->exists();

        return $hasOpenPeriod ? [] : ['no_open_period'];
    }
}
