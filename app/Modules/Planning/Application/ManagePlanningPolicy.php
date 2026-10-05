<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Planning\Domain\DefaultPlanVersion;
use App\Modules\Planning\Domain\PlanningPolicy;
use App\Modules\Planning\Domain\PlanVersion;
use App\Modules\Planning\Domain\PlanVersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The planning policy of the company (PL-FR-04): the publisher rule and the default comparison version of each
 * fiscal year. Without a policy row the rule is off, and the policy reads as version 0, which is the version the
 * first change is made from.
 */
final class ManagePlanningPolicy
{
    public function __construct(
        private readonly Audit $audit,
        private readonly PeriodDirectory $periods,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @return array{require_distinct_publisher: bool, default_versions: list<array{fiscal_year_id: string, plan_version_id: string}>, lock_version: int}
     */
    public function current(): array
    {
        $policy = PlanningPolicy::query()->first();

        return [
            'require_distinct_publisher' => $policy->require_distinct_publisher ?? false,
            'default_versions' => $this->defaults(),
            'lock_version' => $policy->lock_version ?? 0,
        ];
    }

    /**
     * @param  list<array{fiscal_year_id: string, plan_version_id: string|null}>  $defaults  fiscal years to set, or clear with `null`; years not listed keep theirs
     * @return array{require_distinct_publisher: bool, default_versions: list<array{fiscal_year_id: string, plan_version_id: string}>, lock_version: int}
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(bool $requireDistinctPublisher, array $defaults, int $expectedVersion): array
    {
        DB::transaction(function () use ($requireDistinctPublisher, $defaults, $expectedVersion): void {
            $companyId = $this->company->require();
            $before = $this->current();
            $policy = PlanningPolicy::query()->lockForUpdate()->first();

            if ($policy === null) {
                if ($expectedVersion !== 0) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                $policy = PlanningPolicy::create(['require_distinct_publisher' => $requireDistinctPublisher]);
            } else {
                $policy->updateLocked(['require_distinct_publisher' => $requireDistinctPublisher], $expectedVersion);
            }

            $this->applyDefaults($companyId, $defaults);

            $this->audit->record(
                'planning_policy.updated',
                'Planning policy updated.',
                $policy,
                before: ['require_distinct_publisher' => $before['require_distinct_publisher'], 'default_versions' => $before['default_versions']],
                after: ['require_distinct_publisher' => $requireDistinctPublisher, 'default_versions' => $this->defaults()],
                companyId: $policy->company_id,
            );
        });

        return $this->current();
    }

    /**
     * @param  list<array{fiscal_year_id: string, plan_version_id: string|null}>  $defaults
     *
     * @throws ValidationException
     */
    private function applyDefaults(string $companyId, array $defaults): void
    {
        $errors = [];
        $seen = [];

        foreach ($defaults as $index => $entry) {
            $yearId = $entry['fiscal_year_id'];
            $versionId = $entry['plan_version_id'];

            if (isset($seen[$yearId])) {
                $errors["default_versions.{$index}.fiscal_year_id"] = [__('plans.default_year_repeated')];

                continue;
            }

            $seen[$yearId] = true;

            if ($this->periods->fiscalYear($companyId, $yearId) === null) {
                $errors["default_versions.{$index}.fiscal_year_id"] = [__('plans.fiscal_year_unknown')];

                continue;
            }

            if ($versionId === null) {
                continue;
            }

            $version = PlanVersion::query()->with('plan')->find($versionId);
            $problem = match (true) {
                $version === null => (string) __('plans.version_unknown'),
                $version->status !== PlanVersionStatus::Published => (string) __('plans.default_not_published'),
                $version->plan->fiscal_year_id !== $yearId => (string) __('plans.default_other_year'),
                default => null,
            };

            if ($problem !== null) {
                $errors["default_versions.{$index}.plan_version_id"] = [$problem];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($defaults as $entry) {
            if ($entry['plan_version_id'] === null) {
                DefaultPlanVersion::query()->where('fiscal_year_id', $entry['fiscal_year_id'])->delete();

                continue;
            }

            DefaultPlanVersion::query()->updateOrCreate(['fiscal_year_id' => $entry['fiscal_year_id']], ['plan_version_id' => $entry['plan_version_id']]);
        }
    }

    /** @return list<array{fiscal_year_id: string, plan_version_id: string}> */
    private function defaults(): array
    {
        $rows = [];

        foreach (DefaultPlanVersion::query()->orderBy('id')->get() as $default) {
            $rows[] = ['fiscal_year_id' => $default->fiscal_year_id, 'plan_version_id' => $default->plan_version_id];
        }

        return $rows;
    }
}
