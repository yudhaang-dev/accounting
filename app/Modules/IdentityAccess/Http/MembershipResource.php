<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `user` and `roles` loaded (see MembershipRoles).
 *
 * @property-read Membership $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array{id: string, user: array{id: int, name: string, email: string}, status: string, expires_at: string|null, roles: list<array{id: string, name: string, is_active: bool}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        /** @var list<array{id: string, name: string, is_active: bool}> $roles */
        $roles = $this->resource->getRelation('roles')->all();

        return [
            'id' => $this->resource->id,
            'user' => [
                'id' => $this->resource->user->id,
                'name' => $this->resource->user->name,
                'email' => $this->resource->user->email,
            ],
            // `active`, `revoked`, or `expired` once a set expiry has passed.
            'status' => $this->resource->effectiveStatus(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'roles' => $roles,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
