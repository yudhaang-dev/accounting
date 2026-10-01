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
 * (IA-FR-01, IA-AC-01). Per-permission checks arrive with M1-02; integration clients with M3-05.
 */
final class WorkspaceCompanyAccessGate implements CompanyAccessGate
{
    public function authorize(Request $request, string $companyId): void
    {
        $user = Principal::isMachine($request) ? null : $request->user();

        if ($user === null) {
            throw new AuthorizationException;
        }

        $allowed = Membership::query()
            ->grantingAccess()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('workspace_id', Workspace::query()->where('company_id', $companyId)->select('id'))
            ->exists();

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }
}
