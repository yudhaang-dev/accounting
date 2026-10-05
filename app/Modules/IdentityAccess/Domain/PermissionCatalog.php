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
        'dimensions' => ['read', 'manage'],
        'currencies' => ['read', 'manage'],
        'parties' => ['read', 'manage'],
        'posting-profiles' => ['read', 'manage'],
        'cash-accounts' => ['read', 'manage'],
        'cash-transactions' => ['read', 'clear'],
        'documents' => ['read'],
        'open-items' => ['read'],
        'allocations' => ['read', 'manage'],
        'reconciliations' => ['read', 'run', 'waive'],
        'aging-policy' => ['manage'],
        'exchange-rates' => ['read', 'manage'],
        'periods' => ['read', 'manage', 'close', 'reopen', 'revalue'],
        'transaction-types' => ['read'],
        'entries' => ['read', 'create', 'submit', 'approve', 'reject', 'post', 'reverse'],
        'financial-events' => ['create', 'read', 'read-own'],
        'recurring-schedules' => ['read', 'manage'],
        'journals' => ['read', 'post-soft-closed', 'override-source'],
        'opening-balances' => ['import'],
        'fiscal-years' => ['close', 'reopen'],
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
