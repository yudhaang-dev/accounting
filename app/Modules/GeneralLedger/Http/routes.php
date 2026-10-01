<?php

use App\Modules\GeneralLedger\Http\AccountController;
use App\Modules\GeneralLedger\Http\AccountTemplateController;
use App\Modules\GeneralLedger\Http\FiscalCalendarController;
use App\Modules\GeneralLedger\Http\FiscalYearController;
use App\Modules\GeneralLedger\Http\JournalController;
use App\Modules\GeneralLedger\Http\PeriodController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the GeneralLedger module, mounted under /api/v1 (see GeneralLedgerServiceProvider).

Route::companyScoped(function () {
    Route::get('account-templates', [AccountTemplateController::class, 'index'])
        ->middleware('permission:accounts.manage')->name('account-templates.index');
    Route::get('account-templates/{key}', [AccountTemplateController::class, 'show'])
        ->middleware('permission:accounts.manage')->where('key', '[a-z0-9-]+')->name('account-templates.show');

    Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounts.read')->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.manage')->name('accounts.store');
    Route::post('accounts/import-template', [AccountTemplateController::class, 'import'])
        ->middleware('permission:accounts.manage')->name('accounts.import-template');

    Route::get('accounts/{id}', [AccountController::class, 'show'])
        ->middleware('permission:accounts.read')->where('id', ApiServiceProvider::ULID)->name('accounts.show');
    Route::patch('accounts/{id}', [AccountController::class, 'update'])
        ->middleware('permission:accounts.manage')->where('id', ApiServiceProvider::ULID)->name('accounts.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("accounts/{id}/{$action}", [AccountController::class, $action])
            ->middleware('permission:accounts.manage')->where('id', ApiServiceProvider::ULID)->name("accounts.{$action}");
    }

    Route::get('fiscal-calendar', [FiscalCalendarController::class, 'show'])->middleware('permission:periods.read')->name('fiscal-calendar.show');
    Route::post('fiscal-calendar', [FiscalCalendarController::class, 'store'])->middleware('permission:periods.manage')->name('fiscal-calendar.store');
    Route::patch('fiscal-calendar', [FiscalCalendarController::class, 'update'])->middleware('permission:periods.manage')->name('fiscal-calendar.update');

    Route::get('fiscal-years', [FiscalYearController::class, 'index'])->middleware('permission:periods.read')->name('fiscal-years.index');
    Route::post('fiscal-years', [FiscalYearController::class, 'store'])->middleware('permission:periods.manage')->name('fiscal-years.store');
    Route::get('fiscal-years/{id}', [FiscalYearController::class, 'show'])
        ->middleware('permission:periods.read')->where('id', ApiServiceProvider::ULID)->name('fiscal-years.show');

    Route::get('periods', [PeriodController::class, 'index'])->middleware('permission:periods.read')->name('periods.index');
    Route::get('periods/{id}', [PeriodController::class, 'show'])
        ->middleware('permission:periods.read')->where('id', ApiServiceProvider::ULID)->name('periods.show');
    Route::post('periods/{id}/close', [PeriodController::class, 'close'])
        ->middleware('permission:periods.close')->where('id', ApiServiceProvider::ULID)->name('periods.close');
    Route::post('periods/{id}/reopen', [PeriodController::class, 'reopen'])
        ->middleware('permission:periods.reopen')->where('id', ApiServiceProvider::ULID)->name('periods.reopen');

    Route::get('journals', [JournalController::class, 'index'])->middleware('permission:journals.read')->name('journals.index');
    Route::get('journals/{id}', [JournalController::class, 'show'])
        ->middleware('permission:journals.read')->where('id', ApiServiceProvider::ULID)->name('journals.show');
});
