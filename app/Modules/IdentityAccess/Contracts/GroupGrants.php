<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * Explicit access of users to consolidation groups (IA-FR-07). The group is an opaque ID: membership of
 * workspaces never creates a grant, and a grant gives no access to the workspace of any member company.
 */
interface GroupGrants
{
    /** Running and approving include reading. */
    public function can(int $userId, string $groupId, GroupAbility $ability): bool;

    /** Idempotent. */
    public function grant(int $userId, string $groupId, GroupAbility $ability, ?int $grantedBy = null): void;

    /** Revoking `Read` revokes every ability, as they all include it. Idempotent. */
    public function revoke(int $userId, string $groupId, GroupAbility $ability): void;

    /**
     * What the user may do on the group, as `read`, `run` and `approve`: reading is included in the other two.
     *
     * @return list<string>
     */
    public function abilitiesOf(int $userId, string $groupId): array;

    /**
     * The groups the user may read, which is every group they hold a grant on.
     *
     * @return list<string>
     */
    public function groupsOf(int $userId): array;
}
