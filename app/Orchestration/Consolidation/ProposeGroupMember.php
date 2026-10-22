<?php

namespace App\Orchestration\Consolidation;

use App\Modules\Consolidation\Contracts\Groups;
use App\Modules\Consolidation\Contracts\PerimeterMemberData;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Proposes a company as a member of a group, on behalf of a user who runs the group. The user also needs
 * `consolidation.join` in that company: its consent to being read by the group (see CreateGroup).
 */
final class ProposeGroupMember
{
    public function __construct(
        private readonly Groups $groups,
        private readonly AccessContexts $access,
    ) {}

    /** @throws AuthorizationException when the user may not join the company to a group */
    public function __invoke(int $userId, string $groupId, string $companyId, string $controlBasis, string $effectiveFrom): PerimeterMemberData
    {
        $this->access->forUser($userId, $companyId)->requirePermission(CreateGroup::JOIN_PERMISSION);

        return $this->groups->proposeMember($groupId, $companyId, $controlBasis, $effectiveFrom, $userId);
    }
}
