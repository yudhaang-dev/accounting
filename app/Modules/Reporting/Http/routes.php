<?php

use App\Modules\Reporting\Http\AccountRoleMappingController;
use App\Modules\Reporting\Http\GeneralLedgerController;
use App\Modules\Reporting\Http\MovementClassificationController;
use App\Modules\Reporting\Http\ReportDefinitionController;
use App\Modules\Reporting\Http\TrialBalanceController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the Reporting module, mounted under /api/v1 (see ReportingServiceProvider).

Route::companyScoped(function () {
    Route::get('reports/trial-balance', [TrialBalanceController::class, 'show'])->middleware('permission:reports.read')->name('reports.trial-balance');
    Route::get('reports/general-ledger', [GeneralLedgerController::class, 'show'])->middleware('permission:reports.read')->name('reports.general-ledger');

    Route::get('report-definitions', [ReportDefinitionController::class, 'index'])->middleware('permission:reports.read')->name('report-definitions.index');
    Route::post('report-definitions', [ReportDefinitionController::class, 'store'])->middleware('permission:report-definitions.manage')->name('report-definitions.store');
    Route::get('report-definitions/{id}', [ReportDefinitionController::class, 'show'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('report-definitions.show');
    Route::put('report-definitions/{id}', [ReportDefinitionController::class, 'update'])
        ->middleware('permission:report-definitions.manage')->where('id', ApiServiceProvider::ULID)->name('report-definitions.update');
    Route::get('report-definitions/{id}/result', [ReportDefinitionController::class, 'evaluate'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('report-definitions.result');

    Route::get('movement-classifications', [MovementClassificationController::class, 'index'])->middleware('permission:reports.read')->name('movement-classifications.index');
    Route::post('movement-classifications', [MovementClassificationController::class, 'store'])->middleware('permission:report-definitions.manage')->name('movement-classifications.store');
    Route::get('movement-classifications/{id}', [MovementClassificationController::class, 'show'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('movement-classifications.show');
    Route::put('movement-classifications/{id}', [MovementClassificationController::class, 'update'])
        ->middleware('permission:report-definitions.manage')->where('id', ApiServiceProvider::ULID)->name('movement-classifications.update');
    Route::get('movement-classifications/{id}/result', [MovementClassificationController::class, 'evaluate'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('movement-classifications.result');

    Route::get('movement-classifications/{id}/cash-flow', [MovementClassificationController::class, 'cashFlow'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('movement-classifications.cash-flow');
    Route::get('movement-classifications/{id}/equity-changes', [MovementClassificationController::class, 'equityChanges'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('movement-classifications.equity-changes');

    Route::get('reporting/account-role-mappings', [AccountRoleMappingController::class, 'index'])->middleware('permission:reports.read')->name('reporting.account-role-mappings.index');
    Route::put('reporting/account-role-mappings/cash_equivalents', [AccountRoleMappingController::class, 'update'])->middleware('permission:report-definitions.manage')->name('reporting.account-role-mappings.update');
});
