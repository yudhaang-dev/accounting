<?php

namespace App\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Application\CatalogPermissions;
use App\Modules\IdentityAccess\Application\EffectiveAccessContexts;
use App\Modules\IdentityAccess\Application\EloquentGroupGrants;
use App\Modules\IdentityAccess\Application\EloquentIntegrationWebhooks;
use App\Modules\IdentityAccess\Application\EloquentWorkspaceCreators;
use App\Modules\IdentityAccess\Application\EloquentWorkspaces;
use App\Modules\IdentityAccess\Application\IntegrationClientAuthenticator;
use App\Modules\IdentityAccess\Application\RejectDeactivatedLogin;
use App\Modules\IdentityAccess\Application\WorkspaceCompanyAccessGate;
use App\Modules\IdentityAccess\Console\GrantPermissionsCommand;
use App\Modules\IdentityAccess\Console\InviteOwnerCommand;
use App\Modules\IdentityAccess\Console\SyncPermissionsCommand;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Modules\IdentityAccess\Contracts\IntegrationWebhooks;
use App\Modules\IdentityAccess\Contracts\Permissions;
use App\Modules\IdentityAccess\Contracts\WorkspaceCreators;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use App\Modules\IdentityAccess\Http\RequireGroupAccess;
use App\Modules\IdentityAccess\Http\RequirePermission;
use App\Support\Company\CompanyAccessGate;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(WorkspaceCreators::class, EloquentWorkspaceCreators::class);
        $this->app->bind(AccessContexts::class, EffectiveAccessContexts::class);
        $this->app->bind(Permissions::class, CatalogPermissions::class);
        $this->app->bind(IntegrationWebhooks::class, EloquentIntegrationWebhooks::class);
        $this->app->bind(GroupGrants::class, EloquentGroupGrants::class);
        // Replaces the fail-closed default bound by the API platform capability.
        $this->app->bind(CompanyAccessGate::class, WorkspaceCompanyAccessGate::class);

        $this->commands([SyncPermissionsCommand::class, InviteOwnerCommand::class, GrantPermissionsCommand::class]);

        // The token endpoint lives at /api/v1/oauth/token (see Http/routes.php), not Passport's own paths.
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        Event::listen(Login::class, [RejectDeactivatedLogin::class, 'handle']);

        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(5)->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->app->make(Router::class)->aliasMiddleware('permission', RequirePermission::class);
        $this->app->make(Router::class)->aliasMiddleware('group-access', RequireGroupAccess::class);

        // Machine clients: Client Credentials only, short-lived tokens, no refresh tokens (API-TR-23).
        Passport::tokensCan(array_combine(IntegrationScopes::all(), IntegrationScopes::all()));
        Passport::tokensExpireIn(now()->addSeconds((int) config('api.token.ttl_seconds')));

        Auth::viaRequest('integration', fn (Request $request) => $this->app->make(IntegrationClientAuthenticator::class)($request));
    }
}
