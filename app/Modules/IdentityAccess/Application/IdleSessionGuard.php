<?php

namespace App\Modules\IdentityAccess\Application;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends a session that has been idle longer than the security policy of the company allows
 * (IA-FR-05). Activity is the last call on a company route, kept in the session per company, so a
 * user working in two companies is judged by each policy separately.
 */
final class IdleSessionGuard
{
    public function __construct(private readonly SecurityPolicies $policies) {}

    public static function sessionKey(string $companyId): string
    {
        return "ia.last_activity.{$companyId}";
    }

    /**
     * @throws ApiException UNAUTHENTICATED when the session was idle too long; it is signed out
     */
    public function touch(Request $request, string $companyId): void
    {
        $minutes = $this->policies->current($companyId)->idleTimeoutMinutes;

        if ($minutes === null || ! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $key = self::sessionKey($companyId);
        $now = now()->getTimestamp();
        $last = $session->get($key);

        if (is_int($last) && $now - $last > $minutes * 60) {
            Auth::guard('web')->logout();
            $session->invalidate();

            throw new ApiException(ErrorCode::Unauthenticated, __('security.session_idle'));
        }

        $session->put($key, $now);
    }
}
