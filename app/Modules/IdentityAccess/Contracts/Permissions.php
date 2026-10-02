<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * The permission keys the system knows (IA-FR-02), for modules and use cases that let a company
 * name a permission in its own configuration and must reject one that does not exist.
 */
interface Permissions
{
    public function exists(string $permission): bool;
}
