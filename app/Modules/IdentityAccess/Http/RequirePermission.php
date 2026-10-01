<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Contracts\AccessContexts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<key>[,<key>...]` on a company-scoped route: every listed permission is required,
 * otherwise 403 FORBIDDEN. Runs after ResolveCompany, which establishes the access context.
 */
final class RequirePermission
{
    public function __construct(private readonly AccessContexts $contexts) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = $this->contexts->current($request);

        foreach ($permissions as $permission) {
            $context->requirePermission($permission);
        }

        return $next($request);
    }
}
