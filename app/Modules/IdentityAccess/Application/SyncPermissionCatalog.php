<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use Spatie\Permission\Models\Permission;

/**
 * Mirrors PermissionCatalog into the permissions table. Idempotent; keys removed from the catalog
 * are left in place because roles may still reference them.
 */
final class SyncPermissionCatalog
{
    /** @return int number of permissions added */
    public function __invoke(): int
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_diff(PermissionCatalog::all(), $existing);

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        return count($missing);
    }
}
