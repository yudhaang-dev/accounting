<?php

use App\Orchestration\Approval\Http\ApprovalPolicyController;
use App\Orchestration\Company\Http\CompanyActivationController;
use App\Orchestration\Consolidation\Http\GroupController;
use App\Orchestration\FinancialEvents\Http\FinancialEventController;
use App\Orchestration\Ledger\Http\FiscalYearClosingController;
use App\Orchestration\Ledger\Http\HistoryImportController;
use App\Orchestration\Ledger\Http\LedgerPolicyController;
use App\Orchestration\Ledger\Http\OpeningBalanceImportController;
use App\Orchestration\Setup\Http\TemplatePackController;
use App\Orchestration\Workspace\Http\WorkspaceController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// Cross-module use case routes, mounted under /api/v1 (see OrchestrationServiceProvider).

Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware(['auth', 'idempotent'])->name('workspaces.store');

// Groups belong to no company; reaching one takes a grant on it (IA-FR-07), and putting a company into one its consent.
Route::get('groups', [GroupController::class, 'index'])->middleware('auth')->name('groups.index');
Route::post('groups', [GroupController::class, 'store'])->middleware(['auth', 'idempotent'])->name('groups.store');
Route::post('groups/{group}/perimeter', [GroupController::class, 'propose'])
    ->middleware(['auth', 'idempotent', 'group-access:run'])->where('group', ApiServiceProvider::ULID)->name('groups.perimeter.propose');

Route::companyScoped(function () {
    Route::post('activate', [CompanyActivationController::class, 'activate'])
        ->middleware('permission:company.activate')->name('company.activate');

    // Events are idempotent by their source transaction ID (TE-FR-13), not by an Idempotency-Key (API-TR-13).
    Route::post('financial-events', [FinancialEventController::class, 'store'])
        ->middleware('permission:financial-events.create')->withoutMiddleware('idempotent')->name('financial-events.store');
    Route::post('financial-events/batch', [FinancialEventController::class, 'batch'])
        ->middleware('permission:financial-events.create')->withoutMiddleware('idempotent')->name('financial-events.batch');
    Route::get('financial-events/changes', [FinancialEventController::class, 'changes'])
        ->middleware('permission:financial-events.read|financial-events.read-own')->name('financial-events.changes');
    Route::get('financial-events/{eventId}', [FinancialEventController::class, 'show'])
        ->middleware('permission:financial-events.read|financial-events.read-own')->where('eventId', ApiServiceProvider::ULID)->name('financial-events.show');

    // A pack puts together the templates of several modules (ADR-0019); installing it also takes the permission of each picked component.
    Route::get('template-packs', [TemplatePackController::class, 'index'])
        ->middleware('permission:accounts.manage|policies.manage|report-definitions.manage|parties.manage')->name('template-packs.index');
    Route::get('template-packs/{key}', [TemplatePackController::class, 'show'])
        ->middleware('permission:accounts.manage|policies.manage|report-definitions.manage|parties.manage')->where('key', '[a-z0-9-]+')->name('template-packs.show');
    Route::post('template-packs/{key}/install', [TemplatePackController::class, 'install'])
        ->middleware('permission:accounts.manage|policies.manage|report-definitions.manage|parties.manage')->where('key', '[a-z0-9-]+')->name('template-packs.install');

    Route::get('approval-policy', [ApprovalPolicyController::class, 'show'])->middleware('permission:policies.manage')->name('approval-policy.show');
    Route::put('approval-policy', [ApprovalPolicyController::class, 'update'])->middleware('permission:policies.manage')->name('approval-policy.update');

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

    Route::get('fiscal-years/{id}/closing', [FiscalYearClosingController::class, 'show'])
        ->middleware('permission:periods.read')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.closing');
    Route::post('fiscal-years/{id}/close', [FiscalYearClosingController::class, 'store'])
        ->middleware('permission:fiscal-years.close')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.close');
    Route::post('fiscal-years/{id}/reopen', [FiscalYearClosingController::class, 'reopen'])
        ->middleware('permission:fiscal-years.reopen')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.reopen');
});
