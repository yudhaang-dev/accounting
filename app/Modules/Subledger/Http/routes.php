<?php

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Http\PartyController;
use App\Modules\Subledger\Http\PostingProfileController;
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

    Route::get('posting-profiles', [PostingProfileController::class, 'index'])->middleware('permission:posting-profiles.read')->name('posting-profiles.index');
    Route::post('posting-profiles', [PostingProfileController::class, 'store'])->middleware('permission:posting-profiles.manage')->name('posting-profiles.store');
    Route::get('posting-profiles/{id}', [PostingProfileController::class, 'show'])
        ->middleware('permission:posting-profiles.read')->where('id', ApiServiceProvider::ULID)->name('posting-profiles.show');
    Route::patch('posting-profiles/{id}', [PostingProfileController::class, 'update'])
        ->middleware('permission:posting-profiles.manage')->where('id', ApiServiceProvider::ULID)->name('posting-profiles.update');
    Route::put('posting-profiles/{id}/accounts/{role}', [PostingProfileController::class, 'mapAccount'])
        ->middleware('permission:posting-profiles.manage')->where('id', ApiServiceProvider::ULID)->whereIn('role', array_column(ControlRole::cases(), 'value'))->name('posting-profiles.accounts.update');
});
