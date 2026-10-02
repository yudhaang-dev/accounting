<?php

use App\Modules\MasterData\Http\CompanyController;
use App\Modules\MasterData\Http\DimensionTypeController;
use App\Modules\MasterData\Http\DimensionValueController;
use App\Platform\Api\ApiServiceProvider;
use App\Platform\Api\Http\RejectWritesWhenReadOnly;
use Illuminate\Support\Facades\Route;

// API routes for the MasterData module, mounted under /api/v1 (see MasterDataServiceProvider).

Route::companyScoped(function () {
    Route::get('', [CompanyController::class, 'show'])->middleware('permission:company.read')->name('company.show');
    Route::patch('', [CompanyController::class, 'update'])->middleware('permission:company.update')->name('company.update');

    // Status commands must work on a read-only company, otherwise it could never be reactivated.
    Route::post('deactivate', [CompanyController::class, 'deactivate'])
        ->middleware('permission:company.deactivate')->withoutMiddleware(RejectWritesWhenReadOnly::class)->name('company.deactivate');
    Route::post('reactivate', [CompanyController::class, 'reactivate'])
        ->middleware('permission:company.activate')->withoutMiddleware(RejectWritesWhenReadOnly::class)->name('company.reactivate');

    Route::get('dimension-types', [DimensionTypeController::class, 'index'])->middleware('permission:dimensions.read')->name('dimension-types.index');
    Route::post('dimension-types', [DimensionTypeController::class, 'store'])->middleware('permission:dimensions.manage')->name('dimension-types.store');
    Route::get('dimension-types/{id}', [DimensionTypeController::class, 'show'])
        ->middleware('permission:dimensions.read')->where('id', ApiServiceProvider::ULID)->name('dimension-types.show');
    Route::patch('dimension-types/{id}', [DimensionTypeController::class, 'update'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-types.update');

    Route::get('dimension-values', [DimensionValueController::class, 'index'])->middleware('permission:dimensions.read')->name('dimension-values.index');
    Route::post('dimension-values', [DimensionValueController::class, 'store'])->middleware('permission:dimensions.manage')->name('dimension-values.store');
    Route::get('dimension-values/{id}', [DimensionValueController::class, 'show'])
        ->middleware('permission:dimensions.read')->where('id', ApiServiceProvider::ULID)->name('dimension-values.show');
    Route::patch('dimension-values/{id}', [DimensionValueController::class, 'update'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-values.update');
    Route::delete('dimension-values/{id}', [DimensionValueController::class, 'destroy'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-values.destroy');

    foreach (['dimension-types' => DimensionTypeController::class, 'dimension-values' => DimensionValueController::class] as $resource => $controller) {
        foreach (['deactivate', 'reactivate'] as $action) {
            Route::post("{$resource}/{id}/{$action}", [$controller, $action])
                ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name("{$resource}.{$action}");
        }
    }
});
