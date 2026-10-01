<?php

namespace App\Platform\Api;

use Illuminate\Http\Request;

/**
 * Identifies who is calling, for rate limits and idempotency scope (API-TR-13, API-TR-15).
 *
 * IdentityAccess sets the `principal_key` request attribute for integration clients (M3-05);
 * until then a Bearer request is keyed by its token and a session request by its user.
 */
final class Principal
{
    public static function isMachine(Request $request): bool
    {
        return $request->bearerToken() !== null;
    }

    /** Stable key of the caller, or null when the caller is not authenticated. */
    public static function key(Request $request): ?string
    {
        $explicit = $request->attributes->get('principal_key');

        if (is_string($explicit)) {
            return $explicit;
        }

        if (self::isMachine($request)) {
            return 'token:'.sha1((string) $request->bearerToken());
        }

        $user = $request->user();

        return $user === null ? null : 'user:'.$user->getAuthIdentifier();
    }
}
