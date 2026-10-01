<?php

namespace App\Modules\IdentityAccess\Domain;

/**
 * The permissions the system knows (IA-FR-02): protected actions as `<resource>.<action>`. The
 * catalog is code, roles are data; each milestone adds the keys of the endpoints it introduces.
 * `SyncPermissionCatalog` mirrors it into `ia_permissions`.
 */
final class PermissionCatalog
{
    /** @var array<string, list<string>> */
    private const ACTIONS = [
        'company' => ['read', 'update', 'activate', 'deactivate'],
        'accounts' => ['read', 'manage'],
        'periods' => ['read', 'manage', 'close', 'reopen'],
        'entries' => ['read', 'create', 'submit', 'approve', 'reject', 'post', 'reverse'],
        'journals' => ['read'],
        'reports' => ['read', 'export'],
        'audit' => ['read'],
        'memberships' => ['read', 'manage'],
        'roles' => ['read', 'manage'],
        'integration-clients' => ['manage'],
        'policies' => ['manage'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $keys = [];

        foreach (self::ACTIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$resource}.{$action}";
            }
        }

        return $keys;
    }

    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
