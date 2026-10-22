<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * Explicit access of users to consolidation groups (IA-FR-07). The group is an opaque ID: membership of
 * workspaces never creates a grant, and a grant gives no access to the workspace of any member company.
 */
interface GroupGrants
{
    /** Running includes reading. */
    public function can(int $userId, string $groupId, GroupAbility $ability): bool;

    /** Idempotent. */
    public function grant(int $userId, string $groupId, GroupAbility $ability, ?int $grantedBy = null): void;

    /** Revoking `Read` also revokes `Run`. Idempotent. */
    public function revoke(int $userId, string $groupId, GroupAbility $ability): void;
}
