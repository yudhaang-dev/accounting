<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Invitation;
use App\Modules\IdentityAccess\Domain\InvitationStatus;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the invitation of the first owner (ADR-0021): for the one address in configuration, only while
 * no user exists, and without creating an account or a password. The token is returned to the operator
 * once; only its hash is stored.
 */
final class InviteFirstOwner
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @return array{email: string, token: string}
     *
     * @throws RuntimeException when no address is configured or an account already exists
     */
    public function __invoke(): array
    {
        $email = config('access.first_owner_email');

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('FIRST_OWNER_EMAIL is not set.');
        }

        $email = Str::lower($email);

        if (DB::table('users')->exists()) {
            throw new RuntimeException('An account already exists; further accounts come from workspace invitations.');
        }

        $token = Str::random(48);

        DB::transaction(function () use ($email, $token): void {
            Invitation::query()->usable()->whereNull('workspace_id')->update(['status' => InvitationStatus::Revoked->value]);

            $invitation = Invitation::query()->create([
                'workspace_id' => null,
                'email' => $email,
                'token_hash' => Invitation::hashToken($token),
                'role_ids' => [],
                'dimension_value_ids' => [],
                'expires_at' => now()->addDays((int) config('access.invitation_days', 7)),
                'invited_by' => null,
            ]);

            $this->audit->record('invitation.owner_created', "First-owner invitation created for {$email}.", $invitation, after: ['email' => $email]);
        });

        return ['email' => $email, 'token' => $token];
    }
}
