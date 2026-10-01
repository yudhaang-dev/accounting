<?php

namespace App\Orchestration;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Routes of cross-module use cases (ADR-0002). Modules never depend on this layer.
 */
class OrchestrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api/v1')->middleware('api')->group(__DIR__.'/Http/routes.php');
    }
}
