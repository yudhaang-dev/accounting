<?php

namespace App\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Application\EffectiveAccessContexts;
use App\Modules\IdentityAccess\Application\EloquentWorkspaces;
use App\Modules\IdentityAccess\Application\WorkspaceCompanyAccessGate;
use App\Modules\IdentityAccess\Console\SyncPermissionsCommand;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\IdentityAccess\Http\RequirePermission;
use App\Support\Company\CompanyAccessGate;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Routing\Router;

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
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Router::class)->aliasMiddleware('permission', RequirePermission::class);
    }
}
