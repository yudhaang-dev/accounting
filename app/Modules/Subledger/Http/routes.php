<?php

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Http\AgingController;
use App\Modules\Subledger\Http\AllocationController;
use App\Modules\Subledger\Http\BankStatementController;
use App\Modules\Subledger\Http\CashAccountController;
use App\Modules\Subledger\Http\CashBasisExpensesController;
use App\Modules\Subledger\Http\CashTransactionController;
use App\Modules\Subledger\Http\DocumentController;
use App\Modules\Subledger\Http\OpenItemController;
use App\Modules\Subledger\Http\OpenItemRevaluationController;
use App\Modules\Subledger\Http\PartyController;
use App\Modules\Subledger\Http\PostingProfileController;
use App\Modules\Subledger\Http\ReconciliationController;
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

    Route::get('cash-accounts', [CashAccountController::class, 'index'])->middleware('permission:cash-accounts.read')->name('cash-accounts.index');
    Route::post('cash-accounts', [CashAccountController::class, 'store'])->middleware('permission:cash-accounts.manage')->name('cash-accounts.store');
    Route::get('cash-accounts/{id}', [CashAccountController::class, 'show'])
        ->middleware('permission:cash-accounts.read')->where('id', ApiServiceProvider::ULID)->name('cash-accounts.show');
    Route::patch('cash-accounts/{id}', [CashAccountController::class, 'update'])
        ->middleware('permission:cash-accounts.manage')->where('id', ApiServiceProvider::ULID)->name('cash-accounts.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("cash-accounts/{id}/{$action}", [CashAccountController::class, $action])
            ->middleware('permission:cash-accounts.manage')->where('id', ApiServiceProvider::ULID)->name("cash-accounts.{$action}");
    }

    Route::get('cash-transactions', [CashTransactionController::class, 'index'])->middleware('permission:cash-transactions.read')->name('cash-transactions.index');
    Route::get('cash-transactions/{id}', [CashTransactionController::class, 'show'])
        ->middleware('permission:cash-transactions.read')->where('id', ApiServiceProvider::ULID)->name('cash-transactions.show');

    foreach (['clear', 'unclear'] as $action) {
        Route::post("cash-transactions/{id}/{$action}", [CashTransactionController::class, $action])
            ->middleware('permission:cash-transactions.clear')->where('id', ApiServiceProvider::ULID)->name("cash-transactions.{$action}");
    }

    Route::get('cash-accounts/{id}/import-mapping', [BankStatementController::class, 'mapping'])
        ->middleware('permission:bank-statements.read')->where('id', ApiServiceProvider::ULID)->name('cash-accounts.import-mapping.show');
    Route::put('cash-accounts/{id}/import-mapping', [BankStatementController::class, 'saveMapping'])
        ->middleware('permission:bank-statements.manage')->where('id', ApiServiceProvider::ULID)->name('cash-accounts.import-mapping.save');
    Route::post('cash-accounts/{id}/bank-statements', [BankStatementController::class, 'store'])
        ->middleware('permission:bank-statements.manage')->where('id', ApiServiceProvider::ULID)->name('bank-statements.store');
    Route::get('bank-statements', [BankStatementController::class, 'index'])->middleware('permission:bank-statements.read')->name('bank-statements.index');

    foreach (['show' => '', 'lines' => '/lines', 'suggestions' => '/suggestions', 'report' => '/report'] as $action => $suffix) {
        Route::get("bank-statements/{id}{$suffix}", [BankStatementController::class, $action])
            ->middleware('permission:bank-statements.read')->where('id', ApiServiceProvider::ULID)->name("bank-statements.{$action}");
    }

    foreach (['match', 'unmatch'] as $action) {
        Route::post("bank-statement-lines/{id}/{$action}", [BankStatementController::class, $action])
            ->middleware('permission:bank-statements.manage')->where('id', ApiServiceProvider::ULID)->name("bank-statement-lines.{$action}");
    }

    Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:documents.read')->name('documents.index');
    Route::get('documents/{id}', [DocumentController::class, 'show'])
        ->middleware('permission:documents.read')->where('id', ApiServiceProvider::ULID)->name('documents.show');

    Route::get('open-items', [OpenItemController::class, 'index'])->middleware('permission:open-items.read')->name('open-items.index');
    Route::get('open-items/{id}', [OpenItemController::class, 'show'])
        ->middleware('permission:open-items.read')->where('id', ApiServiceProvider::ULID)->name('open-items.show');

    Route::post('periods/{id}/open-item-revaluation', [OpenItemRevaluationController::class, 'store'])
        ->middleware('permission:periods.revalue')->where('id', ApiServiceProvider::ULID)->name('periods.open-item-revaluation');

    Route::get('allocations', [AllocationController::class, 'index'])->middleware('permission:allocations.read')->name('allocations.index');
    Route::post('allocations', [AllocationController::class, 'store'])->middleware('permission:allocations.manage')->name('allocations.store');
    Route::get('allocations/{id}', [AllocationController::class, 'show'])
        ->middleware('permission:allocations.read')->where('id', ApiServiceProvider::ULID)->name('allocations.show');
    Route::get('reports/aging', [AgingController::class, 'show'])->middleware('permission:reports.read')->name('reports.aging');
    Route::get('reports/cash-basis-expenses', [CashBasisExpensesController::class, 'show'])->middleware('permission:reports.read')->name('reports.cash-basis-expenses');
    Route::get('aging-policy', [AgingController::class, 'showPolicy'])->middleware('permission:reports.read')->name('aging-policy.show');
    Route::put('aging-policy', [AgingController::class, 'updatePolicy'])->middleware('permission:aging-policy.manage')->name('aging-policy.update');

    Route::get('reconciliations', [ReconciliationController::class, 'index'])->middleware('permission:reconciliations.read')->name('reconciliations.index');
    Route::post('reconciliations', [ReconciliationController::class, 'store'])->middleware('permission:reconciliations.run')->name('reconciliations.store');
    Route::get('reconciliations/{id}', [ReconciliationController::class, 'show'])
        ->middleware('permission:reconciliations.read')->where('id', ApiServiceProvider::ULID)->name('reconciliations.show');
    Route::post('reconciliations/{id}/waive', [ReconciliationController::class, 'waive'])
        ->middleware('permission:reconciliations.waive')->where('id', ApiServiceProvider::ULID)->name('reconciliations.waive');

    Route::post('allocations/{id}/cancel', [AllocationController::class, 'cancel'])
        ->middleware('permission:allocations.manage')->where('id', ApiServiceProvider::ULID)->name('allocations.cancel');
});
