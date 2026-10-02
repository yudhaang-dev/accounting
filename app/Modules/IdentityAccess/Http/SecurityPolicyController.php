<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\SecurityPolicies;

class SecurityPolicyController
{
    /**
     * The current security policy: permissions that need 2FA, the idle session limit and the IP
     * allowlist of integration clients. A company that never set one has version 0 and no requirements.
     */
    public function show(SecurityPolicies $policies, string $companyId): SecurityPolicyResource
    {
        return new SecurityPolicyResource($policies->current($companyId));
    }

    /**
     * Replaces the policy as a new version; the change is audited and takes effect from the next
     * request. A user without 2FA is refused the listed permissions with `TWO_FACTOR_REQUIRED`.
     * `lock_version` is required.
     */
    public function update(UpdateSecurityPolicyRequest $request, SecurityPolicies $policies, string $companyId): SecurityPolicyResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, two_factor_permissions: list<string>, idle_timeout_minutes: int|null, client_ip_allowlist: list<string>} $data */
        $data = $request->validated();

        return new SecurityPolicyResource($policies->change(
            $companyId,
            $data['two_factor_permissions'],
            $data['idle_timeout_minutes'],
            $data['client_ip_allowlist'],
            (int) $data['lock_version'],
            $user->id,
        ));
    }
}
