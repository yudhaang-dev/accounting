<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Invitation;
use App\Modules\IdentityAccess\Domain\InvitationStatus;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\MembershipStatus;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites people to the workspace of the current company and turns an accepted invitation into a
 * membership (IA-FR-01). The token goes by email and only its hash is stored. Accepting needs a
 * signed-in user with a verified email equal to the invited address, so a leaked token alone
 * grants nothing.
 */
final class ManageInvitations
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly AssignRoles $assignRoles,
        private readonly MembershipDataScopes $dataScopes,
        private readonly Audit $audit,
    ) {}

    /** @return Builder<Invitation> */
    public function query(): Builder
    {
        return Invitation::query()->whereIn(
            'workspace_id',
            Workspace::query()->where('company_id', $this->company->require())->select('id'),
        );
    }

    /**
     * @param  list<string>  $roleIds
     * @param  list<string>  $dataScope  dimension values the member is limited to once they accept; empty means unrestricted
     *
     * @throws ValidationException
     * @throws ApiException FORBIDDEN when the inviter is limited themselves, DIMENSION_INVALID
     */
    public function invite(string $email, array $roleIds, ?string $membershipExpiresAt, User $inviter, array $dataScope = []): Invitation
    {
        $this->dataScopes->assertActorUnrestricted();

        $companyId = $this->company->require();
        $dataScope = array_values(array_unique($dataScope));

        // Fails now, not at acceptance, for a value that is not in this company.
        $this->dataScopes->assertValid($companyId, $dataScope);

        $email = Str::lower($email);
        $workspace = Workspace::query()->where('company_id', $companyId)->firstOrFail();

        if ($this->isActiveMember($workspace, $email)) {
            throw ValidationException::withMessages(['email' => [__('memberships.already_member', ['email' => $email])]]);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($workspace, $email, $roleIds, $dataScope, $membershipExpiresAt, $inviter, $token, $companyId): Invitation {
            // One open invitation per address: a new one replaces the previous.
            $this->query()->usable()->where('email', $email)->update(['status' => InvitationStatus::Revoked->value]);

            $invitation = Invitation::query()->create([
                'workspace_id' => $workspace->id,
                'email' => $email,
                'token_hash' => Invitation::hashToken($token),
                'role_ids' => array_values(array_unique($roleIds)),
                'dimension_value_ids' => $dataScope,
                'membership_expires_at' => $membershipExpiresAt,
                'expires_at' => now()->addDays((int) config('access.invitation_days', 7)),
                'invited_by' => $inviter->id,
            ]);

            $this->audit->record(
                'invitation.created',
                "Invitation sent to {$email}.",
                $invitation,
                after: ['email' => $email, 'role_ids' => $invitation->role_ids, 'data_scope' => $dataScope, 'membership_expires_at' => $membershipExpiresAt],
                companyId: $companyId,
            );

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($workspace->name, $token));

        return $invitation->refresh();
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function revoke(string $invitationId): Invitation
    {
        return DB::transaction(function () use ($invitationId): Invitation {
            $invitation = $this->query()->lockForUpdate()->findOrFail($invitationId);

            if ($invitation->status !== InvitationStatus::Pending) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $invitation->update(['status' => InvitationStatus::Revoked]);

            $this->audit->record('invitation.revoked', "Invitation to {$invitation->email} revoked.", $invitation, companyId: $this->company->require());

            return $invitation;
        });
    }

    /**
     * @throws ValidationException when the token is unknown, used, revoked, expired or meant for another address
     */
    public function accept(string $token, User $user): Workspace
    {
        return DB::transaction(function () use ($token, $user): Workspace {
            $invitation = Invitation::query()
                ->usable()
                ->where('token_hash', Invitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if (
                $invitation === null
                || ! $user->hasVerifiedEmail()
                || Str::lower($user->email) !== $invitation->email
            ) {
                throw ValidationException::withMessages(['token' => [__('memberships.invitation_invalid')]]);
            }

            $workspace = Workspace::query()->findOrFail($invitation->workspace_id);

            $membership = Membership::query()->firstOrNew(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
            $membership->status = MembershipStatus::Active;
            $membership->expires_at = $invitation->membership_expires_at;
            $membership->save();

            // Roles deleted or from elsewhere since the invitation are dropped, not granted.
            /** @var list<string> $roleIds */
            $roleIds = Role::query()->where('company_id', $workspace->company_id)->whereIn('id', $invitation->role_ids)->pluck('id')->all();
            ($this->assignRoles)($user->id, $workspace->company_id, $roleIds);
            $this->dataScopes->replace($membership->id, $workspace->company_id, $invitation->dimension_value_ids);

            $invitation->update(['status' => InvitationStatus::Accepted, 'accepted_at' => now()]);

            $this->audit->record(
                'invitation.accepted',
                "Invitation accepted by {$user->email}.",
                $invitation,
                after: ['membership_id' => $membership->id, 'role_ids' => $roleIds, 'data_scope' => $invitation->dimension_value_ids],
                actor: $user,
                companyId: $workspace->company_id,
            );

            return $workspace;
        });
    }

    private function isActiveMember(Workspace $workspace, string $email): bool
    {
        return Membership::query()
            ->grantingAccess()
            ->where('workspace_id', $workspace->id)
            ->whereIn('user_id', User::query()->whereRaw('lower(email) = ?', [$email])->select('id'))
            ->exists();
    }
}
