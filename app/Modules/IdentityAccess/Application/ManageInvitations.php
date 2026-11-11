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
     * Accepts an invitation. The invited address either already has an account, in which case the
     * signed-in `$user` must be that account with a verified email, or has none, in which case the
     * account is created here (the only place a user is created, ADR-0021) with `$account` and a
     * verified email, since holding the token proves the address. An invitation without a workspace
     * (the first owner's) only creates the account.
     *
     * @param  array{name?: string|null, password?: string|null}  $account
     *
     * @throws ValidationException when the token is unknown, used, revoked, expired or meant for another address, or the account details are missing
     * @throws ApiException UNAUTHENTICATED when the address has an account and nobody is signed in
     */
    public function accept(string $token, ?User $user, array $account = []): AcceptedInvitation
    {
        return DB::transaction(function () use ($token, $user, $account): AcceptedInvitation {
            $invitation = Invitation::query()
                ->usable()
                ->where('token_hash', Invitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ($invitation->workspace_id === null && ! $this->isFirstOwner($invitation))) {
                throw $this->invalid();
            }

            $existing = User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();
            $created = $existing === null;

            if ($created) {
                if ($user !== null) {
                    throw $this->invalid();
                }

                $user = $this->createAccount($invitation->email, $account);
            } elseif ($user === null) {
                throw new ApiException(ErrorCode::Unauthenticated);
            } elseif ($user->id !== $existing->id || ! $user->hasVerifiedEmail()) {
                throw $this->invalid();
            }

            if ($invitation->workspace_id === null) {
                $invitation->update(['status' => InvitationStatus::Accepted, 'accepted_at' => now()]);
                $this->audit->record('invitation.accepted', "First-owner invitation accepted by {$user->email}.", $invitation, actor: $user);

                return new AcceptedInvitation($user, null, true);
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

            return new AcceptedInvitation($user, $workspace, $created);
        });
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => [__('memberships.invitation_invalid')]]);
    }

    /** The first-owner invitation is honoured only for the configured address and while nobody has an account. */
    private function isFirstOwner(Invitation $invitation): bool
    {
        $configured = config('access.first_owner_email');

        return is_string($configured)
            && Str::lower($configured) === $invitation->email
            && ! DB::table('users')->exists();
    }

    /**
     * @param  array{name?: string|null, password?: string|null}  $account
     *
     * @throws ValidationException when the name or password is missing
     */
    private function createAccount(string $email, array $account): User
    {
        $name = $account['name'] ?? '';
        $password = $account['password'] ?? '';
        $errors = [];

        foreach (['name' => $name, 'password' => $password] as $field => $value) {
            if ($value === '') {
                $errors[$field] = [__('validation.required', ['attribute' => $field])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $user = new User;
        $user->forceFill(['name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => now()])->save();

        return $user;
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
