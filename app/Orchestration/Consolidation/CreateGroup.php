<?php

namespace App\Orchestration\Consolidation;

use App\Modules\Consolidation\Contracts\GroupData;
use App\Modules\Consolidation\Contracts\Groups;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a consolidation group and gives its creator the grants to run it and to approve its perimeter
 * (IA-FR-07). A company is put into a group only with its own consent, which is the permission
 * `consolidation.join` there: a group reads the whole of its members, so nobody can pull a company into one
 * just by knowing its ID. The creator needs that permission in the parent company.
 */
final class CreateGroup
{
    public const JOIN_PERMISSION = 'consolidation.join';

    public function __construct(
        private readonly Groups $groups,
        private readonly GroupGrants $grants,
        private readonly AccessContexts $access,
    ) {}

    /** @throws AuthorizationException when the user may not join the parent company to a group */
    public function __invoke(int $userId, string $name, string $parentCompanyId, string $presentationCurrency, ?string $effectiveFrom = null): GroupData
    {
        $this->access->forUser($userId, $parentCompanyId)->requirePermission(self::JOIN_PERMISSION);

        return DB::transaction(function () use ($userId, $name, $parentCompanyId, $presentationCurrency, $effectiveFrom): GroupData {
            $group = $this->groups->create($name, $parentCompanyId, $presentationCurrency, $userId, $effectiveFrom);

            // Running and approving both include reading; neither includes the other.
            $this->grants->grant($userId, $group->id, GroupAbility::Run, $userId);
            $this->grants->grant($userId, $group->id, GroupAbility::Approve, $userId);

            return $group;
        });
    }
}
