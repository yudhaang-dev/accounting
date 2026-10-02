<?php

use App\Orchestration\Company\Http\CompanyActivationController;
use App\Orchestration\Ledger\Http\FiscalYearClosingController;
use App\Orchestration\Ledger\Http\HistoryImportController;
use App\Orchestration\Ledger\Http\LedgerPolicyController;
use App\Orchestration\Ledger\Http\OpeningBalanceImportController;
use App\Orchestration\Workspace\Http\WorkspaceController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// Cross-module use case routes, mounted under /api/v1 (see OrchestrationServiceProvider).

Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware(['auth', 'idempotent'])->name('workspaces.store');

Route::companyScoped(function () {
    Route::post('activate', [CompanyActivationController::class, 'activate'])
        ->middleware('permission:company.activate')->name('company.activate');

    Route::get('ledger-policy', [LedgerPolicyController::class, 'show'])->middleware('permission:policies.manage')->name('ledger-policy.show');
    Route::put('ledger-policy', [LedgerPolicyController::class, 'update'])->middleware('permission:policies.manage')->name('ledger-policy.update');

    Route::get('opening-balance-imports', [OpeningBalanceImportController::class, 'show'])
        ->middleware('permission:journals.read')->name('opening-balance-imports.show');
    Route::post('opening-balance-imports', [OpeningBalanceImportController::class, 'store'])
        ->middleware('permission:opening-balances.import')->name('opening-balance-imports.store');

    Route::get('history-imports', [HistoryImportController::class, 'show'])
        ->middleware('permission:journals.read')->name('history-imports.show');
    Route::post('history-imports', [HistoryImportController::class, 'store'])
        ->middleware('permission:opening-balances.import')->name('history-imports.store');

    Route::post('fiscal-years/{id}/close', [FiscalYearClosingController::class, 'store'])
        ->middleware('permission:fiscal-years.close')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.close');
    Route::post('fiscal-years/{id}/reopen', [FiscalYearClosingController::class, 'reopen'])
        ->middleware('permission:fiscal-years.reopen')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.reopen');
});
