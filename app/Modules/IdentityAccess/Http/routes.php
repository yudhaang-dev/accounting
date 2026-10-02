<?php

use App\Modules\IdentityAccess\Http\MeController;
use App\Modules\IdentityAccess\Http\PermissionController;
use App\Modules\IdentityAccess\Http\RoleController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the IdentityAccess module, mounted under /api/v1 (see IdentityAccessServiceProvider).

Route::get('me', [MeController::class, 'show'])->middleware('auth')->name('me');
Route::get('me/workspaces', [MeController::class, 'workspaces'])->middleware('auth')->name('me.workspaces');

Route::companyScoped(function () {
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.read')->name('permissions.index');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.read')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
    Route::get('roles/{id}', [RoleController::class, 'show'])
        ->middleware('permission:roles.read')->where('id', ApiServiceProvider::ULID)->name('roles.show');
    Route::patch('roles/{id}', [RoleController::class, 'update'])
        ->middleware('permission:roles.manage')->where('id', ApiServiceProvider::ULID)->name('roles.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("roles/{id}/{$action}", [RoleController::class, $action])
            ->middleware('permission:roles.manage')->where('id', ApiServiceProvider::ULID)->name("roles.{$action}");
    }
});
