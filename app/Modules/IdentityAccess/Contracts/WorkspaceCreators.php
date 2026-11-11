<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * Who may create a workspace, and with it a new tenant (D2c). Creating one is not scoped to a company, so
 * the permission cannot be checked the usual way.
 */
interface WorkspaceCreators
{
    /**
     * True for a platform owner (the first owner's account), or for a user who holds `workspaces.create`
     * through a role in a workspace they can currently access.
     */
    public function canCreate(int $userId): bool;
}
