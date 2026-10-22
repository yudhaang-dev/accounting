<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Api\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `group-access:read|run` on a route with a `{group}` parameter: the user needs an explicit grant on that
 * group (IA-FR-07), otherwise 403 FORBIDDEN. Membership of workspaces does not count, and integration
 * clients have no group access.
 */
final class RequireGroupAccess
{
    public function __construct(private readonly GroupGrants $grants) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $group = $request->route('group');

        if ($user === null || Principal::isMachine($request) || ! is_string($group) || ! $this->grants->can((int) $user->getAuthIdentifier(), $group, GroupAbility::from($ability))) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $next($request);
    }
}
