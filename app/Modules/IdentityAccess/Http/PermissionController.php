<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionController
{
    /**
     * The permissions the system knows, as keys a role can hold. The catalog is fixed by the system
     * and not paginated.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (string $key): array => [
                'key' => $key,
                'resource' => explode('.', $key, 2)[0],
                'action' => explode('.', $key, 2)[1],
            ],
            PermissionCatalog::all(),
        )]);
    }
}
