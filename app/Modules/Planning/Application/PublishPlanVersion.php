<?php

namespace App\Modules\Planning\Application;

use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanningPolicy;
use App\Modules\Planning\Domain\PlanVersion;
use App\Modules\Planning\Domain\PlanVersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a draft version (PL-FR-04). From then on its lines never change; changing it means revising it
 * into a new draft. When the planning policy asks for it, whoever changed the version last cannot publish it.
 */
final class PublishPlanVersion
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws ApiException VERSION_CONFLICT, INVALID_STATE_TRANSITION when the version is not a draft, SEGREGATION_OF_DUTIES
     * @throws ValidationException when the version has no lines
     */
    public function __invoke(string $versionId, int $expectedVersion, int $actorId): PlanVersion
    {
        return DB::transaction(function () use ($versionId, $expectedVersion, $actorId): PlanVersion {
            $version = PlanVersion::query()->lockForUpdate()->findOrFail($versionId);

            if ($version->status !== PlanVersionStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            if (PlanningPolicy::query()->first()?->require_distinct_publisher && $version->last_modified_by === $actorId) {
                throw new ApiException(ErrorCode::SegregationOfDuties, (string) __('plans.publisher_must_differ'));
            }

            if (! PlanLine::query()->where('plan_version_id', $version->id)->exists()) {
                throw ValidationException::withMessages(['version' => [__('plans.publish_empty')]]);
            }

            $version->forceFill(['status' => PlanVersionStatus::Published, 'published_by' => $actorId, 'published_at' => now()]);
            $version->updateLocked([], $expectedVersion);

            $this->audit->record(
                'plan_version.published',
                "Plan version {$version->name} published.",
                $version,
                before: ['status' => PlanVersionStatus::Draft->value],
                after: ['status' => PlanVersionStatus::Published->value, 'published_by' => $actorId],
                companyId: $version->company_id,
            );

            return $version->refresh();
        });
    }
}
