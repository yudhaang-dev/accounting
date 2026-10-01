<?php

namespace App\Platform\Api;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Shapes the generated OpenAPI document (ADR-0004, ADR-0010): the two ways to authenticate
 * (API-TR-21, API-TR-22) and who may read the rendered docs. The spec itself comes from the code.
 */
final class OpenApiDocumentation
{
    public static function register(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            // Relative server URL keeps the exported spec identical in every environment.
            $openApi->servers = [new Server('/api/v1')];

            $openApi->secure(
                SecurityScheme::apiKey('cookie', (string) config('session.cookie'))
                    ->as('sessionCookie')
                    ->setDescription('First-party SPA: session cookie from the login flow, plus the XSRF-TOKEN cookie echoed in the X-XSRF-TOKEN header on writes.'),
            );

            $openApi->secure(
                SecurityScheme::http('bearer')
                    ->as('bearerAuth')
                    ->setDescription('Integration clients: OAuth 2.0 client-credentials access token. Treat it as opaque. Writes require an Idempotency-Key header.'),
            );
        });

        // The rendered docs are not public: local use, or an authenticated user in other environments.
        Gate::define('viewApiDocs', fn (?Authenticatable $user = null): bool => app()->isLocal() || $user !== null);
    }
}
