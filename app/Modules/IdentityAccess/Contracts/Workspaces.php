<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * Workspaces and basic membership for the application layer (IA-FR-01).
 */
interface Workspaces
{
    /** Creates a workspace for an existing company and makes the user its first member. */
    public function createFor(int $userId, string $name, string $companyId): WorkspaceData;

    /**
     * Workspaces the user can currently access (active, unexpired membership).
     *
     * @return list<WorkspaceData>
     */
    public function accessibleTo(int $userId): array;
}
