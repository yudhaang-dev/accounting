<?php

namespace App\Support\Access;

/**
 * Holds the permissions of the principal of the current request, next to DataScopeContext. The
 * company access gate sets it once the principal is known and the API layer clears it after the
 * response. Business modules that must tell another module what the actor may do (for instance
 * posting into a soft-closed period, GL-FR-04) read it here without knowing IdentityAccess. Outside a
 * request (console, queue) nobody holds any permission: system processes are not users.
 */
final class ActorPermissions
{
    /** @var list<string> */
    private array $permissions = [];

    /** @param  list<string>  $permissions */
    public function set(array $permissions): void
    {
        $this->permissions = $permissions;
    }

    public function forget(): void
    {
        $this->permissions = [];
    }

    /** @return list<string> */
    public function current(): array
    {
        return $this->permissions;
    }
}
