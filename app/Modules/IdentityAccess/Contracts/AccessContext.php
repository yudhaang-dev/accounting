<?php

namespace App\Modules\IdentityAccess\Contracts;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * What a user may do in one company: the union of the permissions of all their active roles
 * (IA-FR-02). The application layer passes it to business modules; code checks permission keys,
 * never role names (P-01). Data scope (IA-FR-03) will be added here.
 */
final readonly class AccessContext
{
    /** @param  list<string>  $permissions */
    public function __construct(
        public int $userId,
        public string $companyId,
        public array $permissions,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @throws AuthorizationException when the permission is missing
     */
    public function requirePermission(string $permission): void
    {
        if (! $this->can($permission)) {
            throw new AuthorizationException;
        }
    }
}
