<?php

use App\Modules\Planning\Http\PlanController;
use App\Modules\Planning\Http\PlanVersionController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the Planning module, mounted under /api/v1 (see PlanningServiceProvider).

Route::companyScoped(function () {
    Route::get('plans', [PlanController::class, 'index'])->middleware('permission:plans.read')->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->middleware('permission:plans.manage')->name('plans.store');
    Route::get('plans/{id}', [PlanController::class, 'show'])
        ->middleware('permission:plans.read')->where('id', ApiServiceProvider::ULID)->name('plans.show');
    Route::patch('plans/{id}', [PlanController::class, 'update'])
        ->middleware('permission:plans.manage')->where('id', ApiServiceProvider::ULID)->name('plans.update');
    Route::post('plans/{id}/versions', [PlanVersionController::class, 'store'])
        ->middleware('permission:plans.manage')->where('id', ApiServiceProvider::ULID)->name('plans.versions.store');

    Route::get('plan-versions', [PlanVersionController::class, 'index'])->middleware('permission:plans.read')->name('plan-versions.index');
    Route::get('plan-versions/{id}', [PlanVersionController::class, 'show'])
        ->middleware('permission:plans.read')->where('id', ApiServiceProvider::ULID)->name('plan-versions.show');
    Route::patch('plan-versions/{id}', [PlanVersionController::class, 'update'])
        ->middleware('permission:plans.manage')->where('id', ApiServiceProvider::ULID)->name('plan-versions.update');
    Route::post('plan-versions/{id}/revise', [PlanVersionController::class, 'revise'])
        ->middleware('permission:plans.manage')->where('id', ApiServiceProvider::ULID)->name('plan-versions.revise');
});
