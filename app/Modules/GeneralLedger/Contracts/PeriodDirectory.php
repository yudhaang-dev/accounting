<?php

namespace App\Modules\GeneralLedger\Contracts;

use DateTimeInterface;

/**
 * Periods and their status for other modules and the application layer (GL-FR-03, GL-FR-04).
 */
interface PeriodDirectory
{
    /** The period that contains the date, both boundaries included; null outside the calendar. */
    public function forDate(string $companyId, DateTimeInterface|string $date): ?PeriodData;

    /**
     * What keeps the fiscal calendar from being ready: `calendar_missing`, `no_fiscal_year` or
     * `no_open_period`. Empty when ready.
     *
     * @return list<string>
     */
    public function setupProblems(string $companyId): array;
}
