<?php

namespace App\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Application\EloquentWorkspaces;
use App\Modules\IdentityAccess\Application\WorkspaceCompanyAccessGate;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Support\Company\CompanyAccessGate;
use App\Support\Modules\ModuleServiceProvider;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'ia_';
    }

    public function register(): void
    {
        $this->app->bind(Workspaces::class, EloquentWorkspaces::class);
        // Replaces the fail-closed default bound by the API platform capability.
        $this->app->bind(CompanyAccessGate::class, WorkspaceCompanyAccessGate::class);
    }
}
