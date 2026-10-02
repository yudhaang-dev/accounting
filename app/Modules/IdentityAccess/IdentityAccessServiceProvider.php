<?php

namespace App\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Application\EffectiveAccessContexts;
use App\Modules\IdentityAccess\Application\EloquentWorkspaces;
use App\Modules\IdentityAccess\Application\IntegrationClientAuthenticator;
use App\Modules\IdentityAccess\Application\WorkspaceCompanyAccessGate;
use App\Modules\IdentityAccess\Console\SyncPermissionsCommand;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use App\Modules\IdentityAccess\Http\RequirePermission;
use App\Support\Company\CompanyAccessGate;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'ia_';
    }

    public function register(): void
    {
        $this->app->bind(Workspaces::class, EloquentWorkspaces::class);
        $this->app->bind(AccessContexts::class, EffectiveAccessContexts::class);
        // Replaces the fail-closed default bound by the API platform capability.
        $this->app->bind(CompanyAccessGate::class, WorkspaceCompanyAccessGate::class);

        $this->commands([SyncPermissionsCommand::class]);

        // The token endpoint lives at /api/v1/oauth/token (see Http/routes.php), not Passport's own paths.
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Router::class)->aliasMiddleware('permission', RequirePermission::class);

        // Machine clients: Client Credentials only, short-lived tokens, no refresh tokens (API-TR-23).
        Passport::tokensCan(array_combine(IntegrationScopes::all(), IntegrationScopes::all()));
        Passport::tokensExpireIn(now()->addSeconds((int) config('api.token.ttl_seconds')));

        Auth::viaRequest('integration', fn (Request $request) => $this->app->make(IntegrationClientAuthenticator::class)($request));
    }
}
