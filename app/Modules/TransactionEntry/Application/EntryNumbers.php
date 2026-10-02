<?php

namespace App\Modules\TransactionEntry\Application;

use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * Issues entry numbers from the series of a transaction type, creating the series on first use (TE-FR-16).
 */
final class EntryNumbers
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly CompanyContext $company,
    ) {}

    public function next(string $typeKey, CarbonImmutable $date): string
    {
        $companyId = $this->company->require();
        $series = "entry:{$typeKey}";

        try {
            return $this->numbering->next($companyId, $series, $date);
        } catch (NumberSeriesNotFound) {
            /** @var array{pattern: string, padding: int, reset: string} $default */
            $default = config('entries.number');

            $prefix = config('entries.system_number_prefixes')[$typeKey] ?? null;
            $pattern = $prefix === null ? $default['pattern'] : "{$prefix}/{yyyy}/{mm}/{seq}";

            $this->numbering->define($companyId, $series, $pattern, $default['padding'], ResetPeriod::from($default['reset']));

            return $this->numbering->next($companyId, $series, $date);
        }
    }
}
