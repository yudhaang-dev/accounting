<?php

namespace App\Orchestration;

use App\Orchestration\Company\ActivateCompany;
use App\Orchestration\Company\ChartOfAccountsRequirement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Routes of cross-module use cases (ADR-0002). Modules never depend on this layer.
 */
class OrchestrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Prerequisites tagged `company.activation_requirements` are added by the milestones that own them.
        $this->app->tag([ChartOfAccountsRequirement::class], ActivateCompany::REQUIREMENTS_TAG);

        $this->app->when(ActivateCompany::class)
            ->needs('$requirements')
            ->giveTagged(ActivateCompany::REQUIREMENTS_TAG);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')->middleware('api')->group(__DIR__.'/Http/routes.php');
    }
}
