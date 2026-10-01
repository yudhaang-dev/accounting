<?php

namespace App\Platform\Api;

use App\Platform\Api\Http\DenyCompanyAccess;
use App\Platform\Api\Http\ResolveCompany;
use App\Support\Company\CompanyAccessGate;
use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ApiServiceProvider extends ServiceProvider
{
    /** Route constraint for ULIDs in either case (API-TR-04). */
    public const ULID = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

    public function register(): void
    {
        $this->app->bind(CompanyAccessGate::class, DenyCompanyAccess::class);
    }

    public function boot(): void
    {
        URL::forceHttps($this->app->isProduction());

        $this->registerRouteMacros();

        // Lets the SPA obtain the XSRF-TOKEN cookie before login (API-TR-21).
        Route::prefix('api/v1')->middleware('api')->get('csrf-cookie', fn () => response()->noContent())->name('api.csrf-cookie');
    }

    private function registerRouteMacros(): void
    {
        // Company-scoped resources: /api/v1/companies/{companyId}/... (API-TR-03).
        Route::macro('companyScoped', function (Closure $routes): void {
            Route::middleware(['auth', ResolveCompany::class])
                ->prefix('companies/{companyId}')
                ->where(['companyId' => ApiServiceProvider::ULID])
                ->group($routes);
        });

        // State transitions as POST {resource}/{id}/{action} (API-TR-08).
        Route::macro('commands', function (string $resource, string $controller, array $actions): void {
            foreach ($actions as $action) {
                Route::post("{$resource}/{id}/{$action}", [$controller, Str::camel($action)])
                    ->where('id', ApiServiceProvider::ULID)
                    ->name(str_replace('/', '.', $resource).'.'.$action);
            }
        });
    }
}
