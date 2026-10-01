<?php

namespace App\Platform\Localization;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        // Fortify's JSON auth routes (login, password reset, ...) live in the web group, so both groups
        // answer in the language the SPA asks for (API-TR-06).
        $router->pushMiddlewareToGroup('api', SetLocaleFromRequest::class);
        $router->pushMiddlewareToGroup('web', SetLocaleFromRequest::class);
    }
}
