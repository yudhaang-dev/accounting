<?php

namespace App\Platform\Api\Http;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie session and CSRF protection for the SPA on `/api/v1` (ADR-0011, API-TR-21).
 *
 * Requests carrying a Bearer token (machine clients, API-TR-22) skip cookies, session and CSRF
 * entirely: they are authenticated by the token, not by ambient browser credentials.
 */
final class StatefulUnlessBearer
{
    public function __construct(private readonly Pipeline $pipeline) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            return $next($request);
        }

        /** @var Response */
        return $this->pipeline
            ->send($request)
            ->through([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                PreventRequestForgery::class,
            ])
            ->then($next);
    }
}
