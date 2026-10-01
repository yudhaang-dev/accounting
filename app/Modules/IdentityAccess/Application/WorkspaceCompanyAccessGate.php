<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Api\Principal;
use App\Support\Company\CompanyAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * A user may act on a company only through an active, unexpired membership of its workspace
 * (IA-FR-01, IA-AC-01). The effective permissions are attached to the request as its access
 * context for the `permission:` middleware and the application layer (IA-FR-02). Integration
 * clients arrive with M3-05.
 */
final class WorkspaceCompanyAccessGate implements CompanyAccessGate
{
    public function __construct(private readonly EffectiveAccessContexts $contexts) {}

    public function authorize(Request $request, string $companyId): void
    {
        $user = Principal::isMachine($request) ? null : $request->user();

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

        $request->attributes->set(EffectiveAccessContexts::REQUEST_ATTRIBUTE, $this->contexts->forUser($userId, $companyId));
    }
}
