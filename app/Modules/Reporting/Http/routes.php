<?php

use App\Modules\Reporting\Http\TrialBalanceController;
use Illuminate\Support\Facades\Route;

// API routes for the Reporting module, mounted under /api/v1 (see ReportingServiceProvider).

Route::companyScoped(function () {
    Route::get('reports/trial-balance', [TrialBalanceController::class, 'show'])->middleware('permission:reports.read')->name('reports.trial-balance');
});
