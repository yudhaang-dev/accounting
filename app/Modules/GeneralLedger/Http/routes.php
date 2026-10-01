<?php

use App\Modules\GeneralLedger\Http\AccountController;
use App\Modules\GeneralLedger\Http\AccountTemplateController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the GeneralLedger module, mounted under /api/v1 (see GeneralLedgerServiceProvider).

Route::companyScoped(function () {
    Route::get('account-templates', [AccountTemplateController::class, 'index'])
        ->middleware('permission:accounts.manage')->name('account-templates.index');
    Route::get('account-templates/{key}', [AccountTemplateController::class, 'show'])
        ->middleware('permission:accounts.manage')->where('key', '[a-z0-9-]+')->name('account-templates.show');

    Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounts.read')->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.manage')->name('accounts.store');
    Route::post('accounts/import-template', [AccountTemplateController::class, 'import'])
        ->middleware('permission:accounts.manage')->name('accounts.import-template');

    Route::get('accounts/{id}', [AccountController::class, 'show'])
        ->middleware('permission:accounts.read')->where('id', ApiServiceProvider::ULID)->name('accounts.show');
    Route::patch('accounts/{id}', [AccountController::class, 'update'])
        ->middleware('permission:accounts.manage')->where('id', ApiServiceProvider::ULID)->name('accounts.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("accounts/{id}/{$action}", [AccountController::class, $action])
            ->middleware('permission:accounts.manage')->where('id', ApiServiceProvider::ULID)->name("accounts.{$action}");
    }
});
