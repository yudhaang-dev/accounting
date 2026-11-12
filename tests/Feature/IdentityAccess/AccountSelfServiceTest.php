<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\EmailChangeNotification;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

const CURRENT_PASSWORD = 'Old-secret-123';
const NEW_PASSWORD = 'New-secret-456';

function accountUser(): User
{
    return User::factory()->create(['password' => CURRENT_PASSWORD]);
}

function sessionRow(User $user, string $id, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => $lastActivity,
    ]);
}

describe('profile (IA-FR-01)', function () {
    it('changes the name and audits it', function () {
        $user = accountUser();

        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => 'Budi Baru'])->assertOk()->assertJsonPath('data.name', 'Budi Baru');

        expect($user->refresh()->name)->toBe('Budi Baru');
        expect(AuditEntry::query()->where('event', 'account.renamed')->count())->toBe(1);
    });

    it('rejects an empty name and guests', function () {
        $this->actingAs(accountUser())->patchJson('/api/v1/me', ['name' => ''])->assertStatus(422);
        auth()->logout();
        $this->patchJson('/api/v1/me', ['name' => 'X'])->assertStatus(401);
    });
});

describe('email change (IA-FR-04)', function () {
    function requestedToken(User $user, string $email): string
    {
        Notification::fake();
        test()->actingAs($user)->postJson('/api/v1/me/email', ['email' => $email, 'current_password' => CURRENT_PASSWORD])->assertNoContent();

        $token = null;
        Notification::assertSentOnDemand(EmailChangeNotification::class, function ($notification, $channels, $notifiable) use (&$token, $email) {
            $token = $notification->token;

            return $notifiable->routes['mail'] === strtolower($email);
        });

        return $token;
    }

    it('keeps the old address until the new one is confirmed, then verifies the new one', function () {
        $user = accountUser();
        $old = $user->email;
        $user->forceFill(['email_verified_at' => null])->save();

        $token = requestedToken($user, 'New.Address@example.test');

        expect($user->refresh()->email)->toBe($old);

        $this->postJson('/api/v1/me/email/confirm', ['token' => $token])->assertOk()->assertJsonPath('data.email', 'new.address@example.test');

        expect($user->refresh()->email)->toBe('new.address@example.test')->and($user->email_verified_at)->not->toBeNull();
        expect(DB::table('ia_email_changes')->count())->toBe(0);
        expect(AuditEntry::query()->where('event', 'account.email_changed')->count())->toBe(1);

        // The token works once.
        $this->postJson('/api/v1/me/email/confirm', ['token' => $token])->assertStatus(422);
    });

    it('needs the current password and a free address', function () {
        $user = accountUser();
        $other = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/me/email', ['email' => 'x@example.test', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/me/email', ['email' => $other->email, 'current_password' => CURRENT_PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('rejects a wrong or expired token and a token of another user', function () {
        $user = accountUser();
        $token = requestedToken($user, 'a@example.test');

        $this->postJson('/api/v1/me/email/confirm', ['token' => 'wrong'])->assertStatus(422);

        $this->actingAs(accountUser())->postJson('/api/v1/me/email/confirm', ['token' => $token])->assertStatus(422);

        $this->travel(61)->minutes();
        $this->actingAs($user)->postJson('/api/v1/me/email/confirm', ['token' => $token])->assertStatus(422);
    });

    it('refuses to confirm when the address was taken in the meantime', function () {
        $user = accountUser();
        $token = requestedToken($user, 'race@example.test');
        User::factory()->create(['email' => 'race@example.test']);

        $this->actingAs($user)->postJson('/api/v1/me/email/confirm', ['token' => $token])->assertStatus(422);
        expect($user->refresh()->email)->not->toBe('race@example.test');
    });

    it('stores only the hash of the token', function () {
        $token = requestedToken(accountUser(), 'h@example.test');

        expect(DB::table('ia_email_changes')->value('token_hash'))->not->toBe($token)->toBe(hash('sha256', $token));
    });
});

describe('password and sessions (IA-FR-04)', function () {
    it('changes the password only with the current one and ends the other sessions', function () {
        $user = accountUser();
        sessionRow($user, 'other-1', time() - 60);
        sessionRow($user, 'other-2', time() - 120);
        sessionRow(accountUser(), 'stranger', time());

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'wrong', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

        $this->putJson('/api/v1/me/password', [
            'current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        ])->assertNoContent();

        expect(Hash::check(NEW_PASSWORD, $user->refresh()->password))->toBeTrue();
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
        expect(DB::table('sessions')->where('id', 'stranger')->count())->toBe(1);

        $entry = AuditEntry::query()->where('event', 'account.password_changed')->sole();
        expect(json_encode($entry->properties))->not->toContain(NEW_PASSWORD);
    });

    it('rejects a weak password', function () {
        $this->actingAs(accountUser())->putJson('/api/v1/me/password', [
            'current_password' => CURRENT_PASSWORD, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('lists own sessions only, newest first', function () {
        $user = accountUser();
        sessionRow($user, 'old', time() - 500);
        sessionRow($user, 'new', time() - 5);
        sessionRow(accountUser(), 'stranger', time());

        $rows = $this->actingAs($user)->getJson('/api/v1/me/sessions')->assertOk()->json('data');

        expect(array_column($rows, 'id'))->toBe(['new', 'old']);
    });

    it('ends one session of the user, not another users', function () {
        $user = accountUser();
        sessionRow($user, 'mine', time());
        sessionRow(accountUser(), 'theirs', time());

        $this->actingAs($user)->deleteJson('/api/v1/me/sessions/theirs')->assertNotFound();
        expect(DB::table('sessions')->where('id', 'theirs')->count())->toBe(1);

        $this->deleteJson('/api/v1/me/sessions/mine')->assertNoContent();
        expect(DB::table('sessions')->where('id', 'mine')->count())->toBe(0);
    });

    it('ends every other session at once', function () {
        $user = accountUser();
        sessionRow($user, 'a', time());
        sessionRow($user, 'b', time());

        $this->actingAs($user)->deleteJson('/api/v1/me/sessions')->assertNoContent();

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    });

    it('throttles credential changes', function () {
        $user = accountUser();

        foreach (range(1, 5) as $_) {
            $this->actingAs($user)->putJson('/api/v1/me/password', ['current_password' => 'wrong'])->assertStatus(422);
        }

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong'])->assertStatus(429);
    });
});

describe('passkeys (IA-FR-04)', function () {
    it('lists only the own passkeys, oldest first, without the credential', function () {
        $user = accountUser();
        $other = accountUser();

        foreach ([[$user, 'Laptop', 'c-1', '2026-11-01'], [$user, 'Phone', 'c-2', '2026-11-05'], [$other, 'Theirs', 'c-3', '2026-11-02']] as [$owner, $name, $credentialId, $created]) {
            DB::table('passkeys')->insert([
                'user_id' => $owner->id, 'name' => $name, 'credential_id' => $credentialId,
                'credential' => json_encode(['secret' => 'material']), 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/me/passkeys')->assertOk();

        expect(array_column($response->json('data'), 'name'))->toBe(['Laptop', 'Phone']);
        expect($response->getContent())->not->toContain('material')->not->toContain('c-1');
    });

    it('needs a session', function () {
        $this->getJson('/api/v1/me/passkeys')->assertStatus(401);
    });
});

describe('remember-me after sessions end (D3g)', function () {
    it('replaces the remember token when the password changes, so a remembered device cannot sign in again', function () {
        $user = accountUser();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        ])->assertNoContent();

        expect($user->fresh()->remember_token)->not->toBe('old-remember-token')->not->toBeNull();
    });

    it('replaces the remember token when the other sessions end', function () {
        $user = accountUser();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        sessionRow($user, 'other-device', now()->timestamp);

        $this->actingAs($user)->deleteJson('/api/v1/me/sessions')->assertNoContent();

        expect($user->fresh()->remember_token)->not->toBe('old-remember-token');
        expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
    });
});
