<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Stops a deactivated account from signing in, however it tries (password, passkey, two-factor challenge):
 * every guard login fires this event. The message is the same as for wrong credentials, so it does not
 * reveal that the account exists.
 */
final class RejectDeactivatedLogin
{
    /** @throws ValidationException */
    public function handle(Login $event): void
    {
        if ($event->user instanceof User && $event->user->deactivated_at !== null) {
            Auth::guard($event->guard)->logout();

            throw ValidationException::withMessages([config('fortify.username', 'email') => [__('auth.failed')]]);
        }
    }
}
