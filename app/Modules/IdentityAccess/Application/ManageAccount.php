<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Platform\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service on the signed-in user's own account (IA-FR-01, IA-FR-04): name, sign-in address, password
 * and the sessions on other devices. The address changes only after the new one confirms with a token, so
 * the old one keeps working until then and a typo cannot lock the user out. Every change is audited without
 * secrets.
 */
final class ManageAccount
{
    private const EMAIL_CHANGE_TTL_MINUTES = 60;

    public function __construct(private readonly Audit $audit) {}

    public function rename(User $user, string $name): User
    {
        $before = $user->name;

        if ($before !== $name) {
            $user->forceFill(['name' => $name])->save();
            $this->audit->record('account.renamed', 'Account name changed.', $user, before: ['name' => $before], after: ['name' => $name], actor: $user);
        }

        return $user;
    }

    /**
     * Starts an address change: emails a token to the new address and leaves the current one in place. A new
     * request replaces an earlier pending one.
     */
    public function requestEmailChange(User $user, string $email): void
    {
        $email = Str::lower($email);
        $token = Str::random(48);

        DB::table('ia_email_changes')->upsert([[
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::EMAIL_CHANGE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['user_id'], ['email', 'token_hash', 'expires_at', 'updated_at']);

        Notification::route('mail', $email)->notify(new EmailChangeNotification($token));

        $this->audit->record('account.email_change_requested', 'Email change requested.', $user, properties: ['new_email' => $email], actor: $user);
    }

    /**
     * Completes the change with the token from the email. The new address counts as verified because the
     * token proves it was reachable.
     *
     * @throws ValidationException when the token is unknown, expired, or belongs to another user, or when the address was taken meanwhile
     */
    public function confirmEmailChange(User $user, string $token): User
    {
        return DB::transaction(function () use ($user, $token): User {
            $row = DB::table('ia_email_changes')->where('user_id', $user->id)->lockForUpdate()->first();

            if ($row === null || ! hash_equals($row->token_hash, hash('sha256', $token)) || Carbon::parse($row->expires_at)->isPast()) {
                throw ValidationException::withMessages(['token' => [__('account.email_change_invalid')]]);
            }

            if (User::query()->where('email', $row->email)->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['token' => [__('account.email_taken')]]);
            }

            $before = $user->email;
            $user->forceFill(['email' => $row->email, 'email_verified_at' => now()])->save();
            DB::table('ia_email_changes')->where('user_id', $user->id)->delete();

            $this->audit->record('account.email_changed', 'Sign-in address changed.', $user, before: ['email' => $before], after: ['email' => $row->email], actor: $user);

            return $user;
        });
    }

    /** Sets a new password and ends every other session of the user, keeping the one making the change. */
    public function changePassword(User $user, string $password, ?string $currentSessionId): void
    {
        $user->forceFill(['password' => $password])->save();
        $this->endOtherSessions($user, $currentSessionId);

        $this->audit->record('account.password_changed', 'Password changed.', $user, actor: $user);
    }

    /**
     * Passkeys registered by the user, oldest first. Registering and deleting go through the package's own
     * routes (`user/passkeys`); only the list is missing there.
     *
     * @return list<array{id: string, name: string, authenticator: string|null, created_at: string|null, last_used_at: string|null}>
     */
    public function passkeys(User $user): array
    {
        $passkeys = [];

        foreach ($user->passkeys()->orderBy('created_at')->orderBy('id')->get() as $passkey) {
            $passkeys[] = [
                'id' => (string) $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ];
        }

        return $passkeys;
    }

    /**
     * Sessions of the user, most recently active first.
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_active_at: string, is_current: bool}>
     */
    public function sessions(User $user, ?string $currentSessionId): array
    {
        $sessions = [];

        foreach (DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get() as $row) {
            $sessions[] = [
                'id' => (string) $row->id,
                'ip_address' => $row->ip_address === null ? null : (string) $row->ip_address,
                'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'last_active_at' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'is_current' => $row->id === $currentSessionId,
            ];
        }

        return $sessions;
    }

    /** Ends one session of the user other than the current one; returns whether there was such a session. */
    public function endSession(User $user, string $sessionId, ?string $currentSessionId): bool
    {
        if ($sessionId === $currentSessionId) {
            return false;
        }

        $ended = DB::table('sessions')->where('user_id', $user->id)->where('id', $sessionId)->delete() > 0;

        if ($ended) {
            $this->audit->record('account.session_ended', 'Session ended.', $user, actor: $user);
        }

        return $ended;
    }

    public function endOtherSessions(User $user, ?string $currentSessionId): int
    {
        $query = DB::table('sessions')->where('user_id', $user->id);

        if ($currentSessionId !== null) {
            $query->where('id', '!=', $currentSessionId);
        }

        return $query->delete();
    }
}
