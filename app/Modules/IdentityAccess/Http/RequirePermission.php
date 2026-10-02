<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Application\SecurityPolicies;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Api\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<key>[,<key>...]` on a company-scoped route: every listed permission is required,
 * otherwise 403 FORBIDDEN. A listed item may offer alternatives, `<key>|<key>`, of which one is
 * enough (e.g. a user with `entries.read` or a client with `transaction-types.read`). Runs after
 * ResolveCompany, which establishes the access context.
 */
final class RequirePermission
{
    public function __construct(
        private readonly AccessContexts $contexts,
        private readonly SecurityPolicies $policies,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = $this->contexts->current($request);
        $held = [];

        foreach ($permissions as $required) {
            $alternatives = explode('|', $required);
            $granted = array_values(array_filter($alternatives, $context->can(...)));

            if ($granted === []) {
                $context->requirePermission($alternatives[0]);
            }

            $held = [...$held, ...$granted];
        }

        $this->requireTwoFactorWhereDemanded($request, $context->companyId, $held);

        return $next($request);
    }

    /**
     * The security policy can demand 2FA for some permissions (IA-FR-05): a user without a confirmed
     * 2FA is refused for them with the reason, however many roles grant the permission. Integration
     * clients have no account to enable it on.
     *
     * @param  list<string>  $permissions
     *
     * @throws ApiException TWO_FACTOR_REQUIRED
     */
    private function requireTwoFactorWhereDemanded(Request $request, string $companyId, array $permissions): void
    {
        $user = $request->user();

        if ($user === null || Principal::isMachine($request) || ($user->two_factor_confirmed_at ?? null) !== null) {
            return;
        }

        $demanded = array_values(array_intersect($permissions, $this->policies->current($companyId)->twoFactorPermissions));

        if ($demanded !== []) {
            throw new ApiException(ErrorCode::TwoFactorRequired, errors: [
                'permission' => [__('security.two_factor_required', ['permission' => $demanded[0]])],
            ]);
        }
    }
}
