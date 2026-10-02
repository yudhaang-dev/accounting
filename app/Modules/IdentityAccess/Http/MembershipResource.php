<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `user`, `roles` and `data_scope` loaded (see MembershipRoles and MembershipDataScopes).
 *
 * @property-read Membership $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array{id: string, user: array{id: int, name: string, email: string}, status: string, expires_at: string|null, roles: list<array{id: string, name: string, is_active: bool}>, data_scope: list<string>, lock_version: int}
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
            // Dimension values the member is limited to, each with its descendants; empty means no limit.
            'data_scope' => $this->resource->getRelation('data_scope')->all(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
