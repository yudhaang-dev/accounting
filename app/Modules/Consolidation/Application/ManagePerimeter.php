<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Contracts\PerimeterMemberData;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The perimeter of a group (CO-FR-02): who proposes a member, who approves the control assessment and when a
 * member leaves. Control is never inferred, so a proposed member counts for nothing until someone other than the
 * proposer approves it. Every step is audited in the trail of the group and of the company concerned (X-FR-01).
 */
final class ManagePerimeter
{
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /** @throws ValidationException */
    public function propose(string $groupId, string $companyId, string $controlBasis, string $effectiveFrom, int $proposedBy): PerimeterMember
    {
        if ($this->companies->find($companyId) === null) {
            throw ValidationException::withMessages(['company_id' => [__('consolidation.company_unknown')]]);
        }

        try {
            return DB::transaction(function () use ($groupId, $companyId, $controlBasis, $effectiveFrom, $proposedBy): PerimeterMember {
                $group = Group::query()->lockForUpdate()->findOrFail($groupId);

                $member = PerimeterMember::create([
                    'group_id' => $group->id,
                    'company_id' => $companyId,
                    'control_basis' => $controlBasis,
                    'status' => PerimeterMember::PENDING,
                    'effective_from' => $effectiveFrom,
                    'proposed_by' => $proposedBy,
                ])->refresh();

                $this->audit($member, 'group_member.proposed', "Company proposed as a member of group {$group->name}.", after: $this->snapshot($member));

                return $member;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['company_id' => [__('consolidation.already_member')]]);
        }
    }

    /**
     * Adds the parent company as a member already approved: it needs no assessment of control over itself.
     * Called as part of creating the group, inside its transaction.
     */
    public function seedParent(Group $group, int $createdBy): PerimeterMember
    {
        $member = PerimeterMember::create([
            'group_id' => $group->id,
            'company_id' => $group->parent_company_id,
            'control_basis' => (string) __('consolidation.parent_basis'),
            'status' => PerimeterMember::APPROVED,
            'effective_from' => now()->toDateString(),
            'proposed_by' => $createdBy,
            'approved_by' => $createdBy,
            'approved_at' => now(),
        ])->refresh();

        $this->audit($member, 'group_member.approved', "Parent company of group {$group->name} added to the perimeter.", after: $this->snapshot($member));

        return $member;
    }

    /**
     * @throws ApiException SEGREGATION_OF_DUTIES when the approver proposed the member; INVALID_STATE_TRANSITION unless pending
     */
    public function approve(string $groupId, string $memberId, int $approverId): PerimeterMember
    {
        return DB::transaction(function () use ($groupId, $memberId, $approverId): PerimeterMember {
            $member = $this->locked($groupId, $memberId);

            if ($member->status !== PerimeterMember::PENDING) {
                throw new ApiException(ErrorCode::InvalidStateTransition, (string) __('consolidation.not_pending'));
            }

            if ($member->proposed_by === $approverId) {
                throw new ApiException(ErrorCode::SegregationOfDuties, (string) __('consolidation.approver_must_differ'));
            }

            $before = $this->snapshot($member);
            $member->fill(['status' => PerimeterMember::APPROVED, 'approved_by' => $approverId, 'approved_at' => now()])->save();

            $this->audit($member, 'group_member.approved', 'Control assessment of a perimeter member approved.', before: $before, after: $this->snapshot($member));

            return $member->refresh();
        });
    }

    /**
     * The member leaves the perimeter as of `$effectiveTo`; the row stays. A member that was never approved is
     * withdrawn the same way. The parent company cannot leave.
     *
     * @throws ApiException INVALID_STATE_TRANSITION
     * @throws ValidationException
     */
    public function end(string $groupId, string $memberId, string $effectiveTo, int $endedBy): PerimeterMember
    {
        return DB::transaction(function () use ($groupId, $memberId, $effectiveTo, $endedBy): PerimeterMember {
            $member = $this->locked($groupId, $memberId);
            $group = Group::query()->findOrFail($groupId);

            if ($member->status === PerimeterMember::ENDED) {
                throw new ApiException(ErrorCode::InvalidStateTransition, (string) __('consolidation.already_ended'));
            }

            if ($member->company_id === $group->parent_company_id) {
                throw new ApiException(ErrorCode::InvalidStateTransition, (string) __('consolidation.parent_cannot_leave'));
            }

            if ($effectiveTo < $member->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => [__('consolidation.end_before_start')]]);
            }

            $before = $this->snapshot($member);
            $member->fill(['status' => PerimeterMember::ENDED, 'effective_to' => $effectiveTo, 'ended_by' => $endedBy, 'ended_at' => now()])->save();

            $this->audit($member, 'group_member.ended', 'Member left the perimeter.', before: $before, after: $this->snapshot($member));

            return $member->refresh();
        });
    }

    public function data(PerimeterMember $member): PerimeterMemberData
    {
        return new PerimeterMemberData($member->id, $member->group_id, $member->company_id, $member->status);
    }

    private function locked(string $groupId, string $memberId): PerimeterMember
    {
        return PerimeterMember::query()->where('group_id', $groupId)->lockForUpdate()->findOrFail($memberId);
    }

    /** @return array<string, mixed> */
    private function snapshot(PerimeterMember $member): array
    {
        return [
            'company_id' => $member->company_id,
            'status' => $member->status,
            'effective_from' => $member->effective_from->toDateString(),
            'effective_to' => $member->effective_to?->toDateString(),
            'control_basis' => $member->control_basis,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(PerimeterMember $member, string $event, string $description, array $before = [], array $after = []): void
    {
        $this->audit->record($event, $description, $member, before: $before, after: $after, properties: ['group_id' => $member->group_id], companyId: $member->company_id);
    }
}
