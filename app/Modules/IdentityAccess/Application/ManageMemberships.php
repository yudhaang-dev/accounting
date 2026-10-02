<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\MembershipStatus;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes and revokes memberships of the current company's workspace (IA-FR-01). Revoking keeps
 * the row and the audit trail; access stops from the next request (IA-AC-02).
 */
final class ManageMemberships
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly AssignRoles $assignRoles,
        private readonly Audit $audit,
    ) {}

    /** @return Builder<Membership> */
    public function query(): Builder
    {
        return Membership::query()->whereIn(
            'workspace_id',
            Workspace::query()->where('company_id', $this->company->require())->select('id'),
        );
    }

    /**
     * @param  array{expires_at?: string|null, role_ids?: list<string>}  $data
     *
     * @throws ApiException VERSION_CONFLICT
     */
    public function update(string $membershipId, array $data, int $expectedVersion): Membership
    {
        return DB::transaction(function () use ($membershipId, $data, $expectedVersion): Membership {
            $membership = $this->query()->lockForUpdate()->findOrFail($membershipId);
            $companyId = $this->company->require();
            $before = $this->snapshot($membership, $companyId);

            $membership->updateLocked(array_intersect_key($data, ['expires_at' => true]), $expectedVersion);

            if (array_key_exists('role_ids', $data)) {
                ($this->assignRoles)($membership->user_id, $companyId, $data['role_ids']);
            }

            $after = $this->snapshot($membership, $companyId);

            if ($before !== $after) {
                // A role-only change leaves the row untouched, yet stale editors must conflict.
                if ($membership->lock_version === $expectedVersion) {
                    $membership->forceFill(['lock_version' => $expectedVersion + 1])->save();
                }

                $this->audit->record('membership.updated', 'Membership updated.', $membership, before: $before, after: $after, companyId: $companyId);
            }

            return $membership->refresh();
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     * @throws ValidationException
     */
    public function revoke(string $membershipId, int $actorId): Membership
    {
        return DB::transaction(function () use ($membershipId, $actorId): Membership {
            $membership = $this->query()->lockForUpdate()->findOrFail($membershipId);

            if ($membership->user_id === $actorId) {
                throw ValidationException::withMessages(['membership' => [__('memberships.cannot_revoke_self')]]);
            }

            if ($membership->status !== MembershipStatus::Active) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $membership->status = MembershipStatus::Revoked;
            $membership->save();

            $this->audit->record(
                'membership.revoked',
                'Membership revoked.',
                $membership,
                before: ['status' => 'active'],
                after: ['status' => 'revoked'],
                companyId: $this->company->require(),
            );

            return $membership;
        });
    }

    /** @return array{expires_at: string|null, status: string, role_ids: list<string>} */
    private function snapshot(Membership $membership, string $companyId): array
    {
        /** @var list<string> $roleIds */
        $roleIds = DB::table(config('permission.table_names.model_has_roles'))
            ->where('company_id', $companyId)
            ->where('model_id', $membership->user_id)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();

        return [
            'expires_at' => $membership->expires_at?->toIso8601String(),
            'status' => $membership->status->value,
            'role_ids' => $roleIds,
        ];
    }
}
