<?php

namespace App\Modules\IdentityAccess\Contracts;

use App\Support\Access\DataScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What a user may do in one company: the union of the permissions of all their active roles
 * (IA-FR-02). The application layer passes it to business modules; code checks permission keys,
 * never role names (P-01). It also carries the data scope of the user (IA-FR-03), which business
 * modules apply as a filter.
 */
final readonly class AccessContext
{
    public DataScope $dataScope;

    /** @param  list<string>  $permissions */
    public function __construct(
        public int $userId,
        public string $companyId,
        public array $permissions,
        ?DataScope $dataScope = null,
    ) {
        $this->dataScope = $dataScope ?? DataScope::unrestricted();
    }

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
