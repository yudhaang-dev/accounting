<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Modules\IdentityAccess\Database\Factories\MembershipFactory;
use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Access of a user to a workspace. Revoking or expiring it stops access without deleting anything
 * (IA-FR-01, IA-AC-02).
 *
 * @property string $id
 * @property string $workspace_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(MembershipFactory::class)]
class Membership extends UlidModel
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $table = 'ia_memberships';

    protected $fillable = ['workspace_id', 'user_id', 'status', 'expires_at'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Memberships that currently grant access: active and not past their expiry.
     *
     * @param  Builder<Membership>  $query
     */
    public function scopeGrantingAccess(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
