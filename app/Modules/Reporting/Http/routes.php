<?php

use App\Modules\Reporting\Http\ReportDefinitionController;
use App\Modules\Reporting\Http\TrialBalanceController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the Reporting module, mounted under /api/v1 (see ReportingServiceProvider).

Route::companyScoped(function () {
    Route::get('reports/trial-balance', [TrialBalanceController::class, 'show'])->middleware('permission:reports.read')->name('reports.trial-balance');

    Route::get('report-definitions', [ReportDefinitionController::class, 'index'])->middleware('permission:reports.read')->name('report-definitions.index');
    Route::post('report-definitions', [ReportDefinitionController::class, 'store'])->middleware('permission:report-definitions.manage')->name('report-definitions.store');
    Route::get('report-definitions/{id}', [ReportDefinitionController::class, 'show'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('report-definitions.show');
    Route::put('report-definitions/{id}', [ReportDefinitionController::class, 'update'])
        ->middleware('permission:report-definitions.manage')->where('id', ApiServiceProvider::ULID)->name('report-definitions.update');
    Route::get('report-definitions/{id}/result', [ReportDefinitionController::class, 'evaluate'])
        ->middleware('permission:reports.read')->where('id', ApiServiceProvider::ULID)->name('report-definitions.result');
});
