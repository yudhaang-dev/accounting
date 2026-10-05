<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Support\Company\CompanyScope;

/**
 * The lines of a version as CSV rows in the layout that `ImportPlanLines` reads (PL-FR-03). The period is
 * written as its first day, and there is one `dimension.<type key>` column for each dimension type that
 * a line of the version uses.
 */
final class ExportPlanLines
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly PeriodDirectory $periods,
        private readonly DimensionSets $dimensionSets,
    ) {}

    /**
     * @return array{0: list<string>, 1: iterable<list<string|null>>} header and rows
     */
    public function __invoke(PlanVersion $version): array
    {
        $companyId = $version->company_id;

        $setIds = array_values(array_map('strval', PlanLine::query()->where('plan_version_id', $version->id)->distinct()->pluck('dimension_set_id')->all()));
        $described = $this->dimensionSets->describe($companyId, $setIds);

        $keys = [];
        $valueIds = [];

        foreach ($described as $set) {
            foreach ($set as $key => $valueId) {
                $keys[$key] = true;
                $valueIds[$valueId] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);
        $codes = $this->dimensionSets->valueCodes($companyId, array_keys($valueIds));

        $header = ['period', 'account_code', 'amount', 'note', ...array_map(fn (string $key): string => "dimension.{$key}", $keys)];

        $rows = function () use ($version, $companyId, $described, $keys, $codes): iterable {
            $periods = [];
            $accounts = [];

            // The response is streamed after the request has left the company context, so the company is named here.
            $lines = PlanLine::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)->where('plan_version_id', $version->id)
                ->orderBy('period_id')->orderBy('account_id')->orderBy('dimension_set_id')->cursor();

            foreach ($lines as $line) {
                $period = $periods[$line->period_id] ??= $this->periods->find($companyId, $line->period_id);
                $account = $accounts[$line->account_id] ??= $this->accounts->find($companyId, $line->account_id);
                $set = $described[$line->dimension_set_id] ?? [];

                yield [
                    $period?->startDate,
                    $account?->code,
                    (string) $line->amount,
                    $line->note,
                    ...array_map(fn (string $key): ?string => isset($set[$key]) ? ($codes[$set[$key]] ?? null) : null, $keys),
                ];
            }
        };

        return [$header, $rows()];
    }
}
