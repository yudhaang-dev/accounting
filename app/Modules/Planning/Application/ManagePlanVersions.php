<?php

namespace App\Modules\Planning\Application;

use App\Modules\Planning\Domain\Plan;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Modules\Planning\Domain\PlanVersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates, renames and revises plan versions (PL-FR-01, PL-AC-01). A draft changes in place; a
 * published version is revised into a new draft that copies its lines and names it as origin, so the
 * values that were published stay as they were.
 */
final class ManagePlanVersions
{
    private const COPY_CHUNK = 500;

    public function __construct(private readonly Audit $audit) {}

    /**
     * An empty draft of the plan.
     *
     * @throws ValidationException
     */
    public function create(string $planId, string $name, int $actorId): PlanVersion
    {
        return $this->guarded(fn () => DB::transaction(function () use ($planId, $name, $actorId): PlanVersion {
            $plan = Plan::query()->findOrFail($planId);
            $version = PlanVersion::create(['plan_id' => $plan->id, 'name' => $name, 'last_modified_by' => $actorId])->refresh();

            $this->audit->record('plan_version.created', "Plan version {$name} of plan {$plan->name} created.", $version, after: $this->snapshot($version), companyId: $version->company_id);

            return $version;
        }));
    }

    /**
     * @throws ApiException VERSION_CONFLICT, INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function rename(string $versionId, string $name, int $expectedVersion, int $actorId): PlanVersion
    {
        return $this->guarded(fn () => DB::transaction(function () use ($versionId, $name, $expectedVersion, $actorId): PlanVersion {
            $version = PlanVersion::query()->lockForUpdate()->findOrFail($versionId);

            if ($version->status !== PlanVersionStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $before = $version->name;
            $version->updateLocked(['name' => $name, 'last_modified_by' => $actorId], $expectedVersion);

            if ($before !== $name) {
                $this->audit->record('plan_version.renamed', "Plan version {$before} renamed to {$name}.", $version, before: ['name' => $before], after: ['name' => $name], companyId: $version->company_id);
            }

            return $version->refresh();
        }));
    }

    /**
     * Copies a published version, with its lines, into a new draft of the same plan (PL-AC-01).
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not published
     * @throws ValidationException
     */
    public function revise(string $versionId, string $name, int $actorId): PlanVersion
    {
        return $this->guarded(fn () => DB::transaction(function () use ($versionId, $name, $actorId): PlanVersion {
            $origin = PlanVersion::query()->lockForUpdate()->findOrFail($versionId);

            if ($origin->status !== PlanVersionStatus::Published) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $draft = PlanVersion::create([
                'plan_id' => $origin->plan_id,
                'name' => $name,
                'origin_version_id' => $origin->id,
                'last_modified_by' => $actorId,
            ])->refresh();

            $copied = $this->copyLines($origin, $draft);

            $this->audit->record(
                'plan_version.revised',
                "Plan version {$origin->name} revised into draft {$name}.",
                $draft,
                after: [...$this->snapshot($draft), 'lines' => $copied],
                companyId: $draft->company_id,
            );

            return $draft;
        }));
    }

    /** @return int number of lines copied */
    private function copyLines(PlanVersion $from, PlanVersion $to): int
    {
        $copied = 0;

        PlanLine::query()->where('plan_version_id', $from->id)->orderBy('id')->chunk(self::COPY_CHUNK, function ($lines) use ($to, &$copied): void {
            $now = now();

            // Raw rows keep the amounts exactly as stored; the copy is not a new entry of the user.
            PlanLine::query()->insert($lines->map(fn (PlanLine $line): array => [
                'id' => (string) Str::ulid(),
                'company_id' => $line->company_id,
                'plan_version_id' => $to->id,
                'period_id' => $line->period_id,
                'account_id' => $line->account_id,
                'dimension_set_id' => $line->dimension_set_id,
                'amount' => $line->getRawOriginal('amount'),
                'note' => $line->note,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            $copied += $lines->count();
        });

        return $copied;
    }

    /** The name is unique per plan; a concurrent request may take it between check and insert. */
    private function guarded(callable $work): PlanVersion
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('plans.version_name_taken')]]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(PlanVersion $version): array
    {
        return $version->only(['plan_id', 'name', 'status', 'origin_version_id']);
    }
}
