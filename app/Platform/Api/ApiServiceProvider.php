<?php

namespace App\Platform\Api;

use App\Platform\Api\Console\PurgeIdempotencyKeys;
use App\Platform\Api\Http\DenyCompanyAccess;
use App\Platform\Api\Http\Idempotent;
use App\Platform\Api\Http\ResolveCompany;
use App\Support\Company\CompanyAccessGate;
use App\Support\Modules\ModuleServiceProvider;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ApiServiceProvider extends ModuleServiceProvider
{
    /** Route constraint for ULIDs in either case (API-TR-04). */
    public const ULID = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->bind(CompanyAccessGate::class, DenyCompanyAccess::class);

        $this->commands([PurgeIdempotencyKeys::class]);
    }

    public function boot(): void
    {
        parent::boot();

        URL::forceHttps($this->app->isProduction());

        $this->app->make(Router::class)->aliasMiddleware('idempotent', Idempotent::class);

        OpenApiDocumentation::register();

        $this->registerRateLimiter();
        $this->registerRouteMacros();

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('idempotency:purge')->daily());

        // Lets the SPA obtain the XSRF-TOKEN cookie before login (API-TR-21).
        Route::prefix('api/v1')->middleware('api')->get('csrf-cookie', fn () => response()->noContent())->name('api.csrf-cookie');
    }

    private function registerRateLimiter(): void
    {
        // Per user, per integration client, or per IP when unauthenticated (API-TR-15).
        RateLimiter::for('api', function (Request $request) {
            $principal = Principal::key($request);

            if ($principal === null) {
                return Limit::perMinute(config('api.rate_limit.guest_per_minute'))->by('ip:'.$request->ip());
            }

            $perMinute = Principal::isMachine($request)
                ? config('api.rate_limit.client_per_minute')
                : config('api.rate_limit.user_per_minute');

            return Limit::perMinute($perMinute)->by($principal);
        });
    }

    private function registerRouteMacros(): void
    {
        // Company-scoped resources: /api/v1/companies/{companyId}/... (API-TR-03). POST commands accept
        // an Idempotency-Key (API-TR-13); `financial-events` opts out with ->withoutMiddleware('idempotent').
        Route::macro('companyScoped', function (Closure $routes): void {
            Route::middleware(['auth', ResolveCompany::class, 'idempotent'])
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
