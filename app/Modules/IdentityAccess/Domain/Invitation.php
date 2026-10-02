<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Offer to join a workspace with a set of roles (IA-FR-01). Pending until accepted by the user who
 * owns the invited email address, revoked by an admin, or past `expires_at`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property string $token_hash
 * @property list<string> $role_ids
 * @property Carbon|null $membership_expires_at
 * @property Carbon $expires_at
 * @property InvitationStatus $status
 * @property int $invited_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 */
class Invitation extends UlidModel
{
    protected $table = 'ia_invitations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'status' => InvitationStatus::class,
            'membership_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @param  Builder<Invitation>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now());
    }
}
