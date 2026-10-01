<?php

namespace App\Platform\Localization;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('api', SetLocaleFromRequest::class);
    }
}
