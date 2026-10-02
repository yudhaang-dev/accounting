<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\Invitation;
use App\Modules\IdentityAccess\Domain\InvitationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Invitation $resource
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array{id: string, email: string, status: string, role_ids: list<string>, membership_expires_at: string|null, expires_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'email' => $this->resource->email,
            // `pending`, `accepted`, `revoked`, or `expired` once the invitation lapsed unanswered.
            'status' => $this->resource->status === InvitationStatus::Pending && $this->resource->expires_at->isPast()
                ? 'expired'
                : $this->resource->status->value,
            'role_ids' => $this->resource->role_ids,
            'membership_expires_at' => $this->resource->membership_expires_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at->toIso8601String(),
        ];
    }
}
