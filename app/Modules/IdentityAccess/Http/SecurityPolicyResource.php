<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Application\SecurityPolicyData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SecurityPolicyData $resource
 */
class SecurityPolicyResource extends JsonResource
{
    /**
     * @return array{version: int, two_factor_permissions: list<string>, idle_timeout_minutes: int|null, client_ip_allowlist: list<string>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            // 0 while the company has never set a policy.
            'version' => $this->resource->version,
            'two_factor_permissions' => $this->resource->twoFactorPermissions,
            'idle_timeout_minutes' => $this->resource->idleTimeoutMinutes,
            'client_ip_allowlist' => $this->resource->clientIpAllowlist,
            // Same as `version`, named as the concurrency field the update expects (API-TR-14).
            'lock_version' => $this->resource->version,
        ];
    }
}
