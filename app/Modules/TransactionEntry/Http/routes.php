<?php

use App\Modules\TransactionEntry\Http\EntryController;
use App\Modules\TransactionEntry\Http\TransactionTypeController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the TransactionEntry module, mounted under /api/v1 (see TransactionEntryServiceProvider).

Route::companyScoped(function () {
    Route::get('transaction-types', [TransactionTypeController::class, 'index'])->middleware('permission:entries.read')->name('transaction-types.index');
    Route::post('transaction-types', [TransactionTypeController::class, 'store'])->middleware('permission:policies.manage')->name('transaction-types.store');
    Route::get('transaction-types/{id}', [TransactionTypeController::class, 'show'])
        ->middleware('permission:entries.read')->where('id', ApiServiceProvider::ULID)->name('transaction-types.show');

    Route::get('entries', [EntryController::class, 'index'])->middleware('permission:entries.read')->name('entries.index');
    Route::post('entries', [EntryController::class, 'store'])->middleware('permission:entries.create')->name('entries.store');
    Route::get('entries/{id}', [EntryController::class, 'show'])
        ->middleware('permission:entries.read')->where('id', ApiServiceProvider::ULID)->name('entries.show');
    Route::patch('entries/{id}', [EntryController::class, 'update'])
        ->middleware('permission:entries.create')->where('id', ApiServiceProvider::ULID)->name('entries.update');

    $permissions = [
        'submit' => 'entries.submit',
        'approve' => 'entries.approve',
        'reject' => 'entries.reject',
        'post' => 'entries.post',
        'revise' => 'entries.create',
        'cancel' => 'entries.create',
    ];

    foreach ($permissions as $action => $permission) {
        Route::post("entries/{id}/{$action}", [EntryController::class, $action])
            ->middleware("permission:{$permission}")->where('id', ApiServiceProvider::ULID)->name("entries.{$action}");
    }
});
