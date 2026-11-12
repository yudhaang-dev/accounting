<?php

namespace App\Platform\Api\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that keep a browser from guessing types, framing the app, or leaking the URL (D3f).
 *
 * The content security policy applies only to the HTML shell of the SPA: it allows scripts from this
 * origin and the one inline script of the shell, which carries a per-response nonce. It is left out while
 * the Vite dev server runs (its origin and HMR socket are not this origin) and on the API docs, whose
 * viewer loads its own assets. HSTS is sent only over HTTPS, so a local plain-HTTP site is not pinned.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = null;

        if ($this->carriesPolicy($request)) {
            $nonce = base64_encode(random_bytes(16));
            Vite::useCspNonce($nonce);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Passkeys need the WebAuthn calls; everything else a bookkeeping app never uses stays off.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($nonce !== null && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }

    private function carriesPolicy(Request $request): bool
    {
        return ! Vite::isRunningHot() && ! $request->is('docs', 'docs/*');
    }
}
