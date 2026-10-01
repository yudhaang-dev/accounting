<?php

namespace App\Platform\Api;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Shapes the generated OpenAPI documents (ADR-0004, ADR-0010): one document per published API
 * version (`api.versions`), the two ways to authenticate (API-TR-21, API-TR-22) and who may read
 * the rendered docs. The specs themselves come from the code.
 */
final class OpenApiDocumentation
{
    public static function register(): void
    {
        /** @var array<string, array{release: string}> $versions */
        $versions = config('api.versions');

        foreach ($versions as $name => $version) {
            self::registerVersion($name, $version['release']);
        }

        // Only the versioned documents are served; the unnamed default API stays hidden.
        Scramble::configure()->expose(false);

        // /docs/api keeps working as a shortcut to the current version.
        Route::middleware(config('scramble.middleware'))->group(function (): void {
            $current = (string) config('api.current_version');

            Route::redirect('docs/api', "/docs/{$current}");
            Route::redirect('docs/api.json', "/docs/{$current}.json");
        });

        // The rendered docs are not public: local use, or an authenticated user in other environments.
        Gate::define('viewApiDocs', fn (?Authenticatable $user = null): bool => app()->isLocal() || $user !== null);
    }

    private static function registerVersion(string $name, string $release): void
    {
        Scramble::registerApi($name, [
            'api_path' => "api/{$name}",
            'info' => [...config('scramble.info'), 'version' => $release],
        ])
            ->expose(
                ui: fn (Router $router, $action) => $router->get("docs/{$name}", $action)->name("scramble.docs.{$name}.ui"),
                document: fn (Router $router, $action) => $router->get("docs/{$name}.json", $action)->name("scramble.docs.{$name}.document"),
            )
            ->withDocumentTransformers(function (OpenApi $openApi) use ($name): void {
                // Relative server URL keeps the exported spec identical in every environment.
                $openApi->servers = [new Server("/api/{$name}")];

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
    }
}
