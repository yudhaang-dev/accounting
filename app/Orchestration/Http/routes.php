<?php

use App\Orchestration\Company\Http\CompanyActivationController;
use App\Orchestration\Workspace\Http\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Cross-module use case routes, mounted under /api/v1 (see OrchestrationServiceProvider).

Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware(['auth', 'idempotent'])->name('workspaces.store');

Route::companyScoped(function () {
    Route::post('activate', [CompanyActivationController::class, 'activate'])
        ->middleware('permission:company.activate')->name('company.activate');
});
