<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\WorkspaceInvitationNotification;
use App\Modules\IdentityAccess\Domain\Invitation;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

const STRONG_PASSWORD = 'Secret-pass-123';

/** Invites an address to the workspace of a company and returns the token from the captured email. */
function invitationTokenFor(User $admin, string $companyId, string $email): string
{
    Notification::fake();

    test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/invitations", [
        'email' => $email,
        'role_ids' => [Role::where('company_id', $companyId)->where('name', 'viewer')->value('id')],
    ])->assertCreated();

    $token = null;
    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    // The next request is the invitee's, not the admin's.
    app('auth')->forgetGuards();

    return $token;
}

/** Runs `access:invite-owner` and returns the token from the link it prints (the only place it exists). */
function ownerToken(): string
{
    Artisan::call('access:invite-owner');
    preg_match('/token=(\S+)/', Artisan::output(), $match);

    return $match[1];
}

/** @return array<string, string> */
function newAccount(string $token, string $name = 'Newbie'): array
{
    return ['token' => $token, 'name' => $name, 'password' => STRONG_PASSWORD, 'password_confirmation' => STRONG_PASSWORD];
}

describe('accounts only by invitation (ADR-0021)', function () {
    it('has no public registration', function () {
        $this->postJson('/register', newAccount('x') + ['email' => 'x@example.test'])->assertStatus(405);
        expect(Route::has('register.store'))->toBeFalse();

        expect(User::query()->count())->toBe(0);
    });

    it('creates the account of an invited address without a sign-in, verified and signed in', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $token = invitationTokenFor($admin, $companyId, 'Newbie@Example.test');

        $this->postJson('/api/v1/invitations/accept', newAccount($token))
            ->assertOk()->assertJsonPath('data.company_id', $companyId);

        $user = User::where('email', 'newbie@example.test')->firstOrFail();
        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check(STRONG_PASSWORD, $user->password))->toBeTrue()
            ->and(Membership::where('user_id', $user->id)->count())->toBe(1);
        $this->assertAuthenticatedAs($user);
    });

    it('asks for a name and a confirmed strong password when the address has no account', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $token = invitationTokenFor($admin, $companyId, 'newbie@example.test');

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'N', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'N', 'password' => STRONG_PASSWORD, 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        expect(User::where('email', 'newbie@example.test')->exists())->toBeFalse();
    });

    it('never creates an account for a bad token', function () {
        $this->postJson('/api/v1/invitations/accept', newAccount('nope'))
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);

        expect(User::query()->count())->toBe(0);
    });

    it('does not let a guest holding the token take over an existing account', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.test']);
        $token = invitationTokenFor($admin, $companyId, 'guest@example.test');

        $this->postJson('/api/v1/invitations/accept', newAccount($token, 'Other'))->assertUnauthorized();

        expect($guest->fresh()->password)->toBe($guest->password)->and($guest->fresh()->name)->toBe($guest->name);
    });

    it('refuses a user signed in as someone else when the address has no account', function () {
        [$companyId] = createWorkspaceAs($admin = User::factory()->create());
        $token = invitationTokenFor($admin, $companyId, 'newbie@example.test');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/invitations/accept', newAccount($token))
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);

        expect(User::where('email', 'newbie@example.test')->exists())->toBeFalse();
    });
});

describe('the first owner (ADR-0021)', function () {
    it('is invited only at the configured address and only while no user exists', function () {
        config(['access.first_owner_email' => null]);
        $this->artisan('access:invite-owner')->expectsOutputToContain('FIRST_OWNER_EMAIL')->assertFailed();

        config(['access.first_owner_email' => 'Owner@Example.test']);
        User::factory()->create();
        $this->artisan('access:invite-owner')->expectsOutputToContain('already exists')->assertFailed();

        expect(Invitation::query()->count())->toBe(0);
    });

    it('creates only an invitation, then the owner account with the chosen name and password', function () {
        config(['access.first_owner_email' => 'Owner@Example.test']);

        $token = ownerToken();
        expect(User::query()->count())->toBe(0)
            ->and(Invitation::query()->whereNull('workspace_id')->usable()->count())->toBe(1)
            ->and(Invitation::query()->firstOrFail()->token_hash)->not->toBe($token);

        $this->postJson('/api/v1/invitations/accept', newAccount($token, 'Pemilik'))->assertNoContent();

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        expect($owner->hasVerifiedEmail())->toBeTrue()->and(User::query()->count())->toBe(1);
        $this->assertAuthenticatedAs($owner);

        // The command no longer runs once an account exists.
        $this->artisan('access:invite-owner')->assertFailed();
    });

    it('replaces an open owner invitation with a new one', function () {
        config(['access.first_owner_email' => 'owner@example.test']);

        $first = ownerToken();
        $second = ownerToken();

        expect(Invitation::query()->usable()->count())->toBe(1);
        $this->postJson('/api/v1/invitations/accept', newAccount($first))->assertUnprocessable();
        $this->postJson('/api/v1/invitations/accept', newAccount($second))->assertNoContent();
    });

    it('is not honoured when the configured address changed or an account appeared', function () {
        config(['access.first_owner_email' => 'owner@example.test']);
        $body = newAccount(ownerToken());

        config(['access.first_owner_email' => 'someone-else@example.test']);
        $this->postJson('/api/v1/invitations/accept', $body)->assertUnprocessable();

        config(['access.first_owner_email' => 'owner@example.test']);
        User::factory()->create();
        $this->postJson('/api/v1/invitations/accept', $body)->assertUnprocessable();

        expect(User::where('email', 'owner@example.test')->exists())->toBeFalse();
    });

    it('does not write the token to the audit trail', function () {
        config(['access.first_owner_email' => 'owner@example.test']);
        $token = ownerToken();

        expect(json_encode(DB::table('pf_audit_log')->get()))->not->toContain($token);
    });
});
