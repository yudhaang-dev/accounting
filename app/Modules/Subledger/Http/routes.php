<?php

use App\Modules\Subledger\Http\PartyController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the Subledger module, mounted under /api/v1 (see SubledgerServiceProvider).

Route::companyScoped(function () {
    Route::get('parties', [PartyController::class, 'index'])->middleware('permission:parties.read')->name('parties.index');
    Route::post('parties', [PartyController::class, 'store'])->middleware('permission:parties.manage')->name('parties.store');
    Route::get('parties/{id}', [PartyController::class, 'show'])
        ->middleware('permission:parties.read')->where('id', ApiServiceProvider::ULID)->name('parties.show');
    Route::patch('parties/{id}', [PartyController::class, 'update'])
        ->middleware('permission:parties.manage')->where('id', ApiServiceProvider::ULID)->name('parties.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("parties/{id}/{$action}", [PartyController::class, $action])
            ->middleware('permission:parties.manage')->where('id', ApiServiceProvider::ULID)->name("parties.{$action}");
    }
});
