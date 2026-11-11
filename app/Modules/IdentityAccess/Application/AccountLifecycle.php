<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\MembershipStatus;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leaving a workspace and deactivating one's own account (IA-FR-01, X-FR-05). Nothing is deleted: a
 * membership is revoked and an account is only marked, so audit and financial records keep their author.
 * A workspace must never be left without anyone who can manage memberships, so the last such member can
 * neither leave nor deactivate.
 */
final class AccountLifecycle
{
    /** The permission that makes a member able to run the workspace; roles are never recognised by name (P-01). */
    public const ADMIN_PERMISSION = 'memberships.manage';

    public function __construct(
        private readonly Audit $audit,
        private readonly ManageAccount $account,
    ) {}

    /**
     * @throws ValidationException when the user is the last administrator of the workspace
     */
    public function leaveWorkspace(User $user, string $workspaceId): void
    {
        DB::transaction(function () use ($user, $workspaceId): void {
            $workspace = Workspace::query()->findOrFail($workspaceId);
            $membership = Membership::query()->grantingAccess()
                ->where('workspace_id', $workspace->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $this->assertNotLastAdmin($user, $workspace);

            $membership->status = MembershipStatus::Revoked;
            $membership->save();

            $this->audit->record(
                'membership.left',
                'Member left the workspace.',
                $membership,
                before: ['status' => 'active'],
                after: ['status' => 'revoked'],
                actor: $user,
                companyId: $workspace->company_id,
            );
        });
    }

    /**
     * Marks the account as deactivated, revokes its memberships, removes its passkeys and ends every session.
     * The caller has checked the password.
     *
     * @throws ValidationException when the user is the last administrator of any workspace
     */
    public function deactivate(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = Membership::query()->grantingAccess()->where('user_id', $user->id)->lockForUpdate()->get();
            $workspaces = Workspace::query()->whereIn('id', $memberships->pluck('workspace_id'))->get();

            foreach ($workspaces as $workspace) {
                $this->assertNotLastAdmin($user, $workspace);
            }

            foreach ($memberships as $membership) {
                $membership->status = MembershipStatus::Revoked;
                $membership->save();
            }

            $user->forceFill(['deactivated_at' => now()])->save();
            DB::table('ia_email_changes')->where('user_id', $user->id)->delete();
            $user->passkeys()->delete();
            $this->account->endOtherSessions($user, null);

            $this->audit->record(
                'account.deactivated',
                'Account deactivated.',
                $user,
                properties: ['workspace_ids' => $workspaces->pluck('id')->all()],
                actor: $user,
            );
        });
    }

    /** @throws ValidationException */
    private function assertNotLastAdmin(User $user, Workspace $workspace): void
    {
        $admins = $this->administratorIds($workspace);

        if (in_array($user->id, $admins, true) && count($admins) === 1) {
            throw ValidationException::withMessages(['workspace' => [__('account.last_administrator', ['workspace' => $workspace->name])]]);
        }
    }

    /**
     * Users who can manage memberships of the workspace right now: access still granted and a role that is
     * active and carries the permission.
     *
     * @return list<int>
     */
    private function administratorIds(Workspace $workspace): array
    {
        $tables = config('permission.table_names');

        /** @var list<int> $ids */
        $ids = DB::table("{$tables['model_has_roles']} as assigned")
            ->join("{$tables['roles']} as role", 'role.id', '=', 'assigned.role_id')
            ->join("{$tables['role_has_permissions']} as grants", 'grants.role_id', '=', 'role.id')
            ->join("{$tables['permissions']} as permission", 'permission.id', '=', 'grants.permission_id')
            ->where('assigned.model_type', (new User)->getMorphClass())
            ->where('assigned.company_id', $workspace->company_id)
            ->where('role.is_active', true)
            ->where('permission.name', self::ADMIN_PERMISSION)
            ->whereIn('assigned.model_id', Membership::query()->grantingAccess()->where('workspace_id', $workspace->id)->select('user_id'))
            ->distinct()
            ->pluck('assigned.model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }
}
