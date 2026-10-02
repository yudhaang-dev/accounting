<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Contracts\Permissions;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;

final class CatalogPermissions implements Permissions
{
    public function exists(string $permission): bool
    {
        return PermissionCatalog::has($permission);
    }
}
