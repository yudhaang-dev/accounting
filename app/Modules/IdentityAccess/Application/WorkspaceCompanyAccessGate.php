<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Contracts\AccessContext;
use App\Modules\IdentityAccess\Contracts\IntegrationPrincipal;
use App\Modules\IdentityAccess\Domain\IntegrationClient;
use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Api\Principal;
use App\Support\Access\ActorPermissions;
use App\Support\Access\DataScopeContext;
use App\Support\Company\CompanyAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A user may act on a company only through an active, unexpired membership of its workspace
 * (IA-FR-01, IA-AC-01). An integration client may act only on its own company, from an allowed IP,
 * on routes that check a permission, and holds exactly the permissions of its scopes (IA-FR-06).
 * The effective permissions are attached to the request as its access context for the
 * `permission:` middleware and the application layer (IA-FR-02).
 */
final class WorkspaceCompanyAccessGate implements CompanyAccessGate
{
    public function __construct(
        private readonly EffectiveAccessContexts $contexts,
        private readonly IntegrationClientDataScopes $clientScopes,
        private readonly DataScopeContext $dataScope,
        private readonly ActorPermissions $actorPermissions,
        private readonly IdleSessionGuard $idle,
        private readonly SecurityPolicies $policies,
    ) {}

    public function authorize(Request $request, string $companyId): void
    {
        if (Principal::isMachine($request)) {
            $this->authorizeClient($request, $companyId);

            return;
        }

        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException;
        }

        $userId = (int) $user->getAuthIdentifier();

        $allowed = Membership::query()
            ->grantingAccess()
            ->where('user_id', $userId)
            ->whereIn('workspace_id', Workspace::query()->where('company_id', $companyId)->select('id'))
            ->exists();

        if (! $allowed) {
            throw new AuthorizationException;
        }

        $this->idle->touch($request, $companyId);

        $context = $this->contexts->forUser($userId, $companyId);

        $request->attributes->set(EffectiveAccessContexts::REQUEST_ATTRIBUTE, $context);
        // Business modules apply the scope as a filter without knowing this module (IA-FR-03).
        $this->dataScope->set($context->dataScope);
        $this->actorPermissions->set($context->permissions);
    }

    /**
     * @throws AuthorizationException for another company, a route without a permission check, or an IP outside the allowlist
     */
    private function authorizeClient(Request $request, string $companyId): void
    {
        // Set by the `integration` guard once it accepted the Bearer token.
        $client = $request->attributes->get(IntegrationClientAuthenticator::CLIENT_ATTRIBUTE);

        if (! $client instanceof IntegrationClient || $client->company_id !== $companyId) {
            throw new AuthorizationException;
        }

        // Routes written for users only, with no permission check, must stay closed to machines.
        if (! $this->checksPermission($request)) {
            throw new AuthorizationException;
        }

        $allowlist = $this->policies->current($companyId)->clientIpAllowlist;

        if ($allowlist !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            throw new ApiException(ErrorCode::Forbidden, __('security.ip_not_allowed'));
        }

        /** @var list<string> $scopes */
        $scopes = $request->attributes->get(IntegrationClientAuthenticator::SCOPES_ATTRIBUTE, []);

        $request->attributes->set(EffectiveAccessContexts::PRINCIPAL_ATTRIBUTE, new IntegrationPrincipal(
            $client->id,
            $companyId,
            $client->source_system,
            $client->transaction_type_keys,
            $scopes,
        ));
        // Like a member, a client limited to some dimension values sees and sends only what lies inside them (IA-FR-03).
        $dataScope = $this->clientScopes->forClient($client->id, $companyId);

        $request->attributes->set(EffectiveAccessContexts::REQUEST_ATTRIBUTE, new AccessContext(
            null,
            $companyId,
            IntegrationScopes::permissionsFor($scopes),
            $dataScope,
            $client->id,
        ));
        $this->dataScope->set($dataScope);
        $this->actorPermissions->set(IntegrationScopes::permissionsFor($scopes));
    }

    private function checksPermission(Request $request): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                return true;
            }
        }

        return false;
    }
}
