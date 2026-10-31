<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Domain\Plan;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Modules\Planning\Domain\PlanVersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains plans (PL-FR-01, ADR-0017). A plan has its own period and an optional scope of
 * one dimension value. Both are fixed once a version has been published, since changing them changes
 * what the whole history of comparisons means.
 */
final class ManagePlans
{
    private const EDITABLE = ['name', 'objective', 'description'];

    private const FIXED_AFTER_PUBLISH = ['starts_on', 'ends_on', 'scope_dimension_value_id'];

    public function __construct(
        private readonly Audit $audit,
        private readonly PeriodDirectory $periods,
        private readonly DimensionSets $dimensions,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, objective, starts_on, ends_on, scope_dimension_value_id, description
     *
     * @throws ValidationException
     */
    public function create(array $data): Plan
    {
        $companyId = $this->company->require();

        $this->validatePeriod($data['starts_on'], $data['ends_on']);
        $this->validateScope($companyId, $data['scope_dimension_value_id'] ?? null);

        return $this->guarded(fn () => DB::transaction(function () use ($data): Plan {
            $plan = Plan::create(array_intersect_key($data, array_flip([...self::EDITABLE, ...self::FIXED_AFTER_PUBLISH])))->refresh();

            $this->audit->record('plan.created', "Plan {$plan->name} created.", $plan, after: $this->snapshot($plan), companyId: $plan->company_id);

            return $plan;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  any of name, objective, description, and until a version is published starts_on, ends_on, scope_dimension_value_id
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $planId, array $data, int $expectedVersion): Plan
    {
        return $this->guarded(fn () => DB::transaction(function () use ($planId, $data, $expectedVersion): Plan {
            $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
            $changes = array_intersect_key($data, array_flip([...self::EDITABLE, ...self::FIXED_AFTER_PUBLISH]));
            $changes = array_filter($changes, fn (mixed $value, string $field): bool => ! in_array($field, self::FIXED_AFTER_PUBLISH, true) || $value !== $this->current($plan, $field), ARRAY_FILTER_USE_BOTH);

            if (array_intersect_key($changes, array_flip(self::FIXED_AFTER_PUBLISH)) !== []) {
                $this->assertNotPublished($plan);
                $this->validatePeriod($changes['starts_on'] ?? $plan->starts_on->toDateString(), $changes['ends_on'] ?? $plan->ends_on->toDateString());
                $this->assertLinesInside($plan, $changes['starts_on'] ?? $plan->starts_on->toDateString(), $changes['ends_on'] ?? $plan->ends_on->toDateString());

                if (array_key_exists('scope_dimension_value_id', $changes)) {
                    $this->validateScope($plan->company_id, $changes['scope_dimension_value_id']);
                }
            }

            $before = $this->only($plan, array_keys($changes));
            $plan->updateLocked($changes, $expectedVersion);
            $after = $this->only($plan, array_keys($changes));

            if ($before !== $after) {
                $this->audit->record('plan.updated', "Plan {$plan->name} updated.", $plan, before: $before, after: $after, companyId: $plan->company_id);
            }

            return $plan->refresh();
        }));
    }

    /** @throws ValidationException */
    private function validatePeriod(string $startsOn, string $endsOn): void
    {
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => [__('plans.period_reversed')]]);
        }
    }

    /** @throws ValidationException */
    private function validateScope(string $companyId, ?string $valueId): void
    {
        if ($valueId !== null && $this->dimensions->valueCodes($companyId, [$valueId]) === []) {
            throw ValidationException::withMessages(['scope_dimension_value_id' => [__('plans.scope_unknown')]]);
        }
    }

    /** @throws ValidationException */
    private function assertNotPublished(Plan $plan): void
    {
        $published = PlanVersion::query()->where('plan_id', $plan->id)->where('status', '<>', PlanVersionStatus::Draft->value)->exists();

        if ($published) {
            throw ValidationException::withMessages(['starts_on' => [__('plans.period_fixed')]]);
        }
    }

    /** @throws ValidationException */
    private function assertLinesInside(Plan $plan, string $startsOn, string $endsOn): void
    {
        $periodIds = PlanLine::query()
            ->whereIn('plan_version_id', PlanVersion::query()->where('plan_id', $plan->id)->select('id'))
            ->distinct()
            ->pluck('period_id');

        foreach ($periodIds as $periodId) {
            $period = $this->periods->find($plan->company_id, $periodId);

            if ($period === null || $period->startDate > $endsOn || $period->endDate < $startsOn) {
                throw ValidationException::withMessages(['starts_on' => [__('plans.period_lines_outside')]]);
            }
        }
    }

    private function current(Plan $plan, string $field): mixed
    {
        return $this->only($plan, [$field])[$field];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function only(Plan $plan, array $fields): array
    {
        $values = $plan->only($fields);

        foreach (['starts_on', 'ends_on'] as $date) {
            if (isset($values[$date])) {
                $values[$date] = $plan->{$date}->toDateString();
            }
        }

        return $values;
    }

    /** The name is unique per company; a concurrent request may take it between check and insert. */
    private function guarded(callable $work): Plan
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('plans.name_taken')]]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        return $this->only($plan, ['name', 'objective', 'starts_on', 'ends_on', 'scope_dimension_value_id', 'description']);
    }
}
