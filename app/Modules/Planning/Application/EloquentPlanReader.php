<?php

namespace App\Modules\Planning\Application;

use App\Modules\Planning\Contracts\PlanAmount;
use App\Modules\Planning\Contracts\PlanReader;
use App\Modules\Planning\Contracts\PlanVersionData;
use App\Modules\Planning\Domain\DefaultPlanVersion;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;

/** Reads plans for the given company explicitly, independent of the request company context. */
final class EloquentPlanReader implements PlanReader
{
    public function version(string $companyId, string $versionId): ?PlanVersionData
    {
        $version = PlanVersion::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->with(['plan' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])->find($versionId);

        return $version === null ? null : $this->data($version);
    }

    public function defaultVersion(string $companyId, string $fiscalYearId): ?PlanVersionData
    {
        $default = DefaultPlanVersion::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('fiscal_year_id', $fiscalYearId)->first();

        return $default === null ? null : $this->version($companyId, $default->plan_version_id);
    }

    public function amounts(string $companyId, string $versionId, array $periodIds, ?string $accountId = null, ?array $dimensionSetIds = null): array
    {
        if ($periodIds === [] || $dimensionSetIds === []) {
            return [];
        }

        $query = PlanLine::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('plan_version_id', $versionId)
            ->whereIn('period_id', $periodIds)
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->when($dimensionSetIds !== null, fn ($q) => $q->whereIn('dimension_set_id', $dimensionSetIds ?? []));

        $totals = [];

        foreach ($query->cursor() as $line) {
            $totals[$line->account_id] = ($totals[$line->account_id] ?? BigDecimal::zero())->plus($line->amount);
        }

        return array_map(fn (string $id): PlanAmount => new PlanAmount($id, (string) $totals[$id]), array_keys($totals));
    }

    private function data(PlanVersion $version): PlanVersionData
    {
        $plan = $version->plan;

        return new PlanVersionData($version->id, $version->name, $version->status->value, $plan->starts_on->toDateString(), $plan->ends_on->toDateString(), $plan->scope_dimension_value_id);
    }
}
