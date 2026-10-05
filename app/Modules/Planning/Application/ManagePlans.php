<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Planning\Domain\Plan;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains plans (PL-FR-01). A plan belongs to one fiscal year for good, since its
 * versions hold lines of that year's periods.
 */
final class ManagePlans
{
    private const EDITABLE = ['name', 'description'];

    public function __construct(
        private readonly Audit $audit,
        private readonly PeriodDirectory $periods,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, fiscal_year_id, description
     *
     * @throws ValidationException
     */
    public function create(array $data): Plan
    {
        $companyId = $this->company->require();

        if ($this->periods->fiscalYear($companyId, $data['fiscal_year_id']) === null) {
            throw ValidationException::withMessages(['fiscal_year_id' => [__('plans.fiscal_year_unknown')]]);
        }

        return $this->guarded(fn () => DB::transaction(function () use ($data): Plan {
            $plan = Plan::create(array_intersect_key($data, array_flip([...self::EDITABLE, 'fiscal_year_id'])))->refresh();

            $this->audit->record('plan.created', "Plan {$plan->name} created.", $plan, after: $this->snapshot($plan), companyId: $plan->company_id);

            return $plan;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  any of name, description
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $planId, array $data, int $expectedVersion): Plan
    {
        return $this->guarded(fn () => DB::transaction(function () use ($planId, $data, $expectedVersion): Plan {
            $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
            $changes = array_intersect_key($data, array_flip(self::EDITABLE));

            $before = $plan->only(array_keys($changes));
            $plan->updateLocked($changes, $expectedVersion);
            $after = $plan->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record('plan.updated', "Plan {$plan->name} updated.", $plan, before: $before, after: $after, companyId: $plan->company_id);
            }

            return $plan->refresh();
        }));
    }

    /** The name is unique per company and fiscal year; a concurrent request may take it between check and insert. */
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
        return $plan->only(['name', 'fiscal_year_id', 'description']);
    }
}
