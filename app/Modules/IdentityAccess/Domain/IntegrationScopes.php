<?php

namespace App\Modules\IdentityAccess\Domain;

/**
 * OAuth scopes an integration client can hold (API-TR-23) and the permissions each stands for. A
 * machine principal has no roles: its permissions are exactly those of its scopes, so the same
 * `permission:` checks that guard users guard clients (P-06). Permissions of endpoints that come
 * later (`financial-events.*`, M5-07) are added to the catalog with them.
 */
final class IntegrationScopes
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'transaction-types:read' => ['transaction-types.read'],
        'financial-events:write' => ['financial-events.create'],
        'financial-events:read:self' => ['financial-events.read-own'],
        'exchange-rates:write' => ['exchange-rates.manage'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string> sorted, without duplicates
     */
    public static function permissionsFor(array $scopes): array
    {
        $permissions = [];

        foreach ($scopes as $scope) {
            $permissions = [...$permissions, ...(self::PERMISSIONS[$scope] ?? [])];
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }
}
