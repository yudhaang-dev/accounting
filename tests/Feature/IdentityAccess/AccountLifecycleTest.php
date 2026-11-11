<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

const LIFECYCLE_PASSWORD = 'Lifecycle-pass-1';

function lifecycleAdmin(): User
{
    return User::factory()->create(['password' => LIFECYCLE_PASSWORD]);
}

function workspaceIdOf(string $companyId): string
{
    return Workspace::where('company_id', $companyId)->value('id');
}

describe('leaving a workspace (IA-FR-01)', function () {
    it('revokes the membership of a member and keeps everything else', function () {
        [$companyId] = createWorkspaceAs(lifecycleAdmin());
        $member = memberWithRoles($companyId, 'viewer');
        $workspaceId = workspaceIdOf($companyId);

        $this->actingAs($member)->postJson("/api/v1/me/workspaces/{$workspaceId}/leave")->assertNoContent();

        expect(Membership::where('user_id', $member->id)->value('status')->value)->toBe('revoked');
        expect(User::find($member->id))->not->toBeNull();
        expect(AuditEntry::query()->where('event', 'membership.left')->where('company_id', $companyId)->count())->toBe(1);
        $this->getJson('/api/v1/me/workspaces')->assertJsonCount(0, 'data');
    });

    it('does not let the last administrator leave, but lets one of two', function () {
        $first = lifecycleAdmin();
        [$companyId] = createWorkspaceAs($first);
        $workspaceId = workspaceIdOf($companyId);

        $this->actingAs($first)->postJson("/api/v1/me/workspaces/{$workspaceId}/leave")
            ->assertStatus(422)->assertJsonValidationErrors('workspace');
        expect(Membership::where('user_id', $first->id)->value('status')->value)->toBe('active');

        $second = memberWithRoles($companyId, 'accounting_admin');

        $this->actingAs($first)->postJson("/api/v1/me/workspaces/{$workspaceId}/leave")->assertNoContent();

        // Now the second is the last one.
        $this->actingAs($second)->postJson("/api/v1/me/workspaces/{$workspaceId}/leave")->assertStatus(422);
    });

    it('does not count an administrator whose membership lapsed or whose role is inactive', function () {
        $first = lifecycleAdmin();
        [$companyId] = createWorkspaceAs($first);
        $workspaceId = workspaceIdOf($companyId);
        $second = memberWithRoles($companyId, 'accounting_admin');
        Membership::where('user_id', $second->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($first)->postJson("/api/v1/me/workspaces/{$workspaceId}/leave")->assertStatus(422);
    });

    it('answers 404 for a workspace the user has no access to', function () {
        [$companyId] = createWorkspaceAs(lifecycleAdmin());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson('/api/v1/me/workspaces/'.workspaceIdOf($companyId).'/leave')->assertNotFound();
    });
});

describe('deactivating the account (X-FR-05)', function () {
    function deactivateBody(string $password = LIFECYCLE_PASSWORD): array
    {
        return ['current_password' => $password];
    }

    it('needs the current password', function () {
        $user = lifecycleAdmin();

        $this->actingAs($user)->postJson('/api/v1/me/deactivate', deactivateBody('wrong'))
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        expect($user->refresh()->deactivated_at)->toBeNull();
    });

    it('marks the account, revokes memberships, ends sessions and keeps the rows', function () {
        [$companyId] = createWorkspaceAs(lifecycleAdmin());
        $member = memberWithRoles($companyId, 'viewer');
        $member->forceFill(['password' => LIFECYCLE_PASSWORD])->save();
        DB::table('sessions')->insert(['id' => 'x', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($member)->postJson('/api/v1/me/deactivate', deactivateBody())->assertNoContent();

        expect($member->refresh()->deactivated_at)->not->toBeNull();
        expect(Membership::where('user_id', $member->id)->value('status')->value)->toBe('revoked');
        expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
        expect(AuditEntry::query()->where('event', 'account.deactivated')->count())->toBe(1);
    });

    it('refuses while the user is the last administrator of a workspace', function () {
        $admin = lifecycleAdmin();
        createWorkspaceAs($admin);

        $this->actingAs($admin)->postJson('/api/v1/me/deactivate', deactivateBody())
            ->assertStatus(422)->assertJsonValidationErrors('workspace');

        expect($admin->refresh()->deactivated_at)->toBeNull();
    });

    it('can no longer sign in, and the failure looks like wrong credentials', function () {
        $user = lifecycleAdmin();
        $this->actingAs($user)->postJson('/api/v1/me/deactivate', deactivateBody())->assertNoContent();
        app('auth')->forgetGuards();

        $this->postJson('/login', ['email' => $user->email, 'password' => LIFECYCLE_PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->getJson('/api/v1/me')->assertStatus(401);
    });
});
