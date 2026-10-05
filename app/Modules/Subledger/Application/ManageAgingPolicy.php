<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\AgingPolicy;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The aging policy of the company (SL-FR-08). Without one the defaults apply, and the policy reads as version 0,
 * which is the version the first change is made from.
 */
final class ManageAgingPolicy
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @return array{basis: string, buckets: list<int>, lock_version: int}
     */
    public function current(): array
    {
        $policy = AgingPolicy::query()->first();

        return [
            'basis' => $policy->basis ?? AgingPolicy::DEFAULT_BASIS,
            'buckets' => $policy === null ? AgingPolicy::DEFAULT_BUCKETS : array_map('intval', $policy->buckets),
            'lock_version' => $policy->lock_version ?? 0,
        ];
    }

    /**
     * @param  array{basis: string, buckets: list<int>}  $data
     * @return array{basis: string, buckets: list<int>, lock_version: int}
     *
     * @throws ApiException VERSION_CONFLICT
     */
    public function update(array $data, int $expectedVersion): array
    {
        DB::transaction(function () use ($data, $expectedVersion): void {
            $before = $this->current();
            $policy = AgingPolicy::query()->lockForUpdate()->first();

            if ($policy === null) {
                if ($expectedVersion !== 0) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                $policy = AgingPolicy::create(['basis' => $data['basis'], 'buckets' => $data['buckets']]);
            } else {
                $policy->updateLocked(['basis' => $data['basis'], 'buckets' => $data['buckets']], $expectedVersion);
            }

            $this->audit->record(
                'aging_policy.updated',
                'Aging policy updated.',
                $policy,
                before: ['basis' => $before['basis'], 'buckets' => $before['buckets']],
                after: ['basis' => $data['basis'], 'buckets' => $data['buckets']],
                companyId: $policy->company_id,
            );
        });

        return $this->current();
    }
}
