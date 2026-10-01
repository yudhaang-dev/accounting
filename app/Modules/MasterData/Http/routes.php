<?php

use App\Modules\MasterData\Http\CompanyController;
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
});
