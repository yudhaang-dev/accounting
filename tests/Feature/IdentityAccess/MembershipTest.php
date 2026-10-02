<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\WorkspaceInvitationNotification;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Domain\Invitation;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\Notification;

function roleId(string $companyId, string $name): string
{
    return Role::where('company_id', $companyId)->where('name', $name)->value('id');
}

function membershipOf(User $user): Membership
{
    return Membership::where('user_id', $user->id)->firstOrFail();
}

/** Sends an invitation and returns its token, as read from the captured email. */
function inviteAs(User $admin, string $companyId, string $email, array $extra = []): array
{
    Notification::fake();

    $response = test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/invitations", [
        'email' => $email,
        'role_ids' => [roleId($companyId, 'viewer')],
        ...$extra,
    ])->assertCreated();

    $token = null;
    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return [$response, $token];
}

describe('memberships API (IA-FR-01)', function () {
    it('lists members with roles and status, and filters by status', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $revoked = memberWithRoles($companyId, 'auditor');
        $expired = memberWithRoles($companyId);
        membershipOf($revoked)->update(['status' => 'revoked']);
        membershipOf($expired)->update(['expires_at' => now()->subDay()]);
        $url = "/api/v1/companies/{$companyId}/memberships";

        $all = $this->actingAs($admin)->getJson($url)->assertOk();
        $byEmail = collect($all->json('data'))->keyBy('user.email');

        expect($all->json('data'))->toHaveCount(4)
            ->and($byEmail[$viewer->email]['status'])->toBe('active')
            ->and($byEmail[$viewer->email]['roles'][0]['name'])->toBe('viewer')
            ->and($byEmail[$revoked->email]['status'])->toBe('revoked')
            ->and($byEmail[$expired->email]['status'])->toBe('expired');

        expect($this->actingAs($admin)->getJson("{$url}?filter[status]=expired")->json('data'))->toHaveCount(1)
            ->and($this->actingAs($admin)->getJson("{$url}?filter[status]=active")->json('data'))->toHaveCount(2);
    });

    it('revokes a membership at once and keeps the audit trail (IA-AC-02)', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        $id = membershipOf($member)->id;
        $accounts = "/api/v1/companies/{$companyId}/accounts";

        $this->actingAs($member)->getJson($accounts)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/memberships/{$id}/revoke")
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->actingAs($member)->getJson($accounts)->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/me/workspaces')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/memberships/{$id}/revoke")
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);

        expect(Membership::find($id))->not->toBeNull()
            ->and(AuditEntry::where('company_id', $companyId)->where('event', 'membership.revoked')->count())->toBe(1);
    });

    it('does not let a user revoke their own membership', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());

        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/memberships/".membershipOf($admin)->id.'/revoke')
            ->assertUnprocessable();
        expect(membershipOf($admin)->status->value)->toBe('active');
    });

    it('stops access when the expiry passes without any change (IA-AC-02)', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        $id = membershipOf($member)->id;
        $accounts = "/api/v1/companies/{$companyId}/accounts";

        $this->actingAs($admin)->patchJson("/api/v1/companies/{$companyId}/memberships/{$id}", [
            'lock_version' => 1,
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();
        $this->actingAs($member)->getJson($accounts)->assertOk();

        $this->travel(2)->days();

        $this->actingAs($member)->getJson($accounts)->assertForbidden();
        $this->actingAs($admin)->getJson("/api/v1/companies/{$companyId}/memberships/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'expired');
    });

    it('replaces roles, bumps the version and refuses a stale edit', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        $id = membershipOf($member)->id;
        $url = "/api/v1/companies/{$companyId}/memberships/{$id}";

        $this->actingAs($admin)->patchJson($url, ['lock_version' => 1, 'role_ids' => [roleId($companyId, 'auditor')]])
            ->assertOk()->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.roles.0.name', 'auditor');

        expect(app(AccessContexts::class)->forUser($member->id, $companyId)->can('audit.read'))->toBeTrue();

        $this->actingAs($admin)->patchJson($url, ['lock_version' => 1, 'role_ids' => []])
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->actingAs($admin)->patchJson($url, ['lock_version' => 2, 'role_ids' => []])->assertOk();

        expect(app(AccessContexts::class)->forUser($member->id, $companyId)->permissions)->toBe([])
            ->and(AuditEntry::where('company_id', $companyId)->where('event', 'membership.updated')->count())->toBe(2);
    });

    it('rejects roles of another company', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs(User::factory()->create(), 'Other');
        $member = memberWithRoles($companyId, 'viewer');

        $this->actingAs($admin)->patchJson("/api/v1/companies/{$companyId}/memberships/".membershipOf($member)->id, [
            'lock_version' => 1,
            'role_ids' => [roleId($otherCompanyId, 'viewer')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['role_ids.0']);
    });

    it('requires memberships.manage to change and memberships.read to look', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $id = membershipOf($viewer)->id;

        $this->actingAs($viewer)->getJson("/api/v1/companies/{$companyId}/memberships")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/companies/{$companyId}/memberships/{$id}/revoke")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/companies/{$companyId}/invitations", ['email' => 'a@b.test', 'role_ids' => []])->assertForbidden();
    });

    it('does not reach memberships of another company', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs($other = User::factory()->create(), 'Other');

        $this->actingAs($admin)->getJson("/api/v1/companies/{$companyId}/memberships/".membershipOf($other)->id)->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/memberships/".membershipOf($other)->id.'/revoke')->assertNotFound();
    });
});

describe('invitations (IA-FR-01)', function () {
    it('emails an invitation and turns it into a membership once accepted', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.test']);
        $expiry = now()->addMonth()->startOfSecond();

        [$response, $token] = inviteAs($admin, $companyId, 'Guest@Example.test', ['expires_at' => $expiry->toIso8601String()]);

        $response->assertJsonPath('data.email', 'guest@example.test')->assertJsonPath('data.status', 'pending');
        expect(Invitation::first()->token_hash)->not->toBe($token);

        $this->actingAs($guest)->getJson("/api/v1/companies/{$companyId}/accounts")->assertForbidden();

        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()->assertJsonPath('data.company_id', $companyId);

        $membership = membershipOf($guest);
        expect($membership->expires_at->equalTo($expiry))->toBeTrue()
            ->and(app(AccessContexts::class)->forUser($guest->id, $companyId)->can('reports.read'))->toBeTrue();
        $this->actingAs($guest)->getJson("/api/v1/companies/{$companyId}/accounts")->assertOk();

        $this->actingAs($admin)->getJson("/api/v1/companies/{$companyId}/invitations")
            ->assertOk()->assertJsonPath('data.0.status', 'accepted');
        expect(AuditEntry::where('company_id', $companyId)->whereIn('event', ['invitation.created', 'invitation.accepted'])->count())->toBe(2);

        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnprocessable();
    });

    it('refuses acceptance by another address, an unverified user or a bad token', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [, $token] = inviteAs($admin, $companyId, 'guest@example.test');

        $this->actingAs(User::factory()->create(['email' => 'other@example.test']))
            ->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->actingAs(User::factory()->unverified()->create(['email' => 'guest@example.test']))
            ->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['email' => 'guest2@example.test']))
            ->postJson('/api/v1/invitations/accept', ['token' => 'nope'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnauthorized();
    });

    it('refuses an expired or revoked invitation', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.test']);
        [$response, $token] = inviteAs($admin, $companyId, 'guest@example.test');

        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/invitations/".$response->json('data.id').'/revoke')
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/invitations/".$response->json('data.id').'/revoke')
            ->assertStatus(409);
        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnprocessable();

        [, $second] = inviteAs($admin, $companyId, 'guest@example.test');
        $this->travel(config('access.invitation_days') + 1)->days();
        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $second])->assertUnprocessable();
    });

    it('lets a new invitation replace the open one for the same address', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.test']);
        [, $first] = inviteAs($admin, $companyId, 'guest@example.test');
        [, $second] = inviteAs($admin, $companyId, 'guest@example.test');

        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $first])->assertUnprocessable();
        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $second])->assertOk();
    });

    it('reinstates a revoked member when they accept a new invitation', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $member = memberWithRoles($companyId, 'viewer');
        membershipOf($member)->update(['status' => 'revoked']);
        [, $token] = inviteAs($admin, $companyId, $member->email, ['role_ids' => [roleId($companyId, 'auditor')]]);

        $this->actingAs($member)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        expect(Membership::where('user_id', $member->id)->count())->toBe(1)
            ->and(membershipOf($member)->status->value)->toBe('active')
            ->and(app(AccessContexts::class)->forUser($member->id, $companyId)->can('audit.read'))->toBeTrue();
    });

    it('refuses to invite an active member and validates roles', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs(User::factory()->create(), 'Other');
        $member = memberWithRoles($companyId, 'viewer');
        $url = "/api/v1/companies/{$companyId}/invitations";

        $this->actingAs($admin)->postJson($url, ['email' => $member->email, 'role_ids' => [roleId($companyId, 'viewer')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->actingAs($admin)->postJson($url, ['email' => 'new@example.test', 'role_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
        $this->actingAs($admin)->postJson($url, ['email' => 'new@example.test', 'role_ids' => [roleId($otherCompanyId, 'viewer')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_ids.0']);
    });
});
