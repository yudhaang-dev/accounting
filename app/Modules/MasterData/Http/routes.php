<?php

use App\Modules\MasterData\Http\CompanyController;
use App\Modules\MasterData\Http\CurrencyController;
use App\Modules\MasterData\Http\DimensionTypeController;
use App\Modules\MasterData\Http\DimensionValueController;
use App\Modules\MasterData\Http\ExchangeRateController;
use App\Modules\MasterData\Http\IsoCurrencyController;
use App\Modules\MasterData\Http\RateTypeController;
use App\Platform\Api\ApiServiceProvider;
use App\Platform\Api\Http\RejectWritesWhenReadOnly;
use Illuminate\Support\Facades\Route;

// API routes for the MasterData module, mounted under /api/v1 (see MasterDataServiceProvider).

Route::companyScoped(function () {
    Route::get('', [CompanyController::class, 'show'])->middleware('permission:company.read')->name('company.show');
    Route::patch('', [CompanyController::class, 'update'])->middleware('permission:company.update')->name('company.update');

    // Status commands must work on a read-only company, otherwise it could never be reactivated.
    Route::post('deactivate', [CompanyController::class, 'deactivate'])
        ->middleware('permission:company.deactivate')->withoutMiddleware(RejectWritesWhenReadOnly::class)->name('company.deactivate');
    Route::post('reactivate', [CompanyController::class, 'reactivate'])
        ->middleware('permission:company.activate')->withoutMiddleware(RejectWritesWhenReadOnly::class)->name('company.reactivate');

    Route::get('dimension-types', [DimensionTypeController::class, 'index'])->middleware('permission:dimensions.read')->name('dimension-types.index');
    Route::post('dimension-types', [DimensionTypeController::class, 'store'])->middleware('permission:dimensions.manage')->name('dimension-types.store');
    Route::get('dimension-types/{id}', [DimensionTypeController::class, 'show'])
        ->middleware('permission:dimensions.read')->where('id', ApiServiceProvider::ULID)->name('dimension-types.show');
    Route::patch('dimension-types/{id}', [DimensionTypeController::class, 'update'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-types.update');

    Route::get('dimension-values', [DimensionValueController::class, 'index'])->middleware('permission:dimensions.read')->name('dimension-values.index');
    Route::post('dimension-values', [DimensionValueController::class, 'store'])->middleware('permission:dimensions.manage')->name('dimension-values.store');
    Route::get('dimension-values/{id}', [DimensionValueController::class, 'show'])
        ->middleware('permission:dimensions.read')->where('id', ApiServiceProvider::ULID)->name('dimension-values.show');
    Route::patch('dimension-values/{id}', [DimensionValueController::class, 'update'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-values.update');
    Route::delete('dimension-values/{id}', [DimensionValueController::class, 'destroy'])
        ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name('dimension-values.destroy');

    foreach (['dimension-types' => DimensionTypeController::class, 'dimension-values' => DimensionValueController::class] as $resource => $controller) {
        foreach (['deactivate', 'reactivate'] as $action) {
            Route::post("{$resource}/{id}/{$action}", [$controller, $action])
                ->middleware('permission:dimensions.manage')->where('id', ApiServiceProvider::ULID)->name("{$resource}.{$action}");
        }
    }

    Route::get('iso-currencies', [IsoCurrencyController::class, 'index'])->middleware('permission:currencies.read')->name('iso-currencies.index');

    $code = '[A-Z]{3}';
    Route::get('currencies', [CurrencyController::class, 'index'])->middleware('permission:currencies.read')->name('currencies.index');
    Route::post('currencies', [CurrencyController::class, 'store'])->middleware('permission:currencies.manage')->name('currencies.store');
    Route::get('currencies/{code}', [CurrencyController::class, 'show'])
        ->middleware('permission:currencies.read')->where('code', $code)->name('currencies.show');
    Route::patch('currencies/{code}', [CurrencyController::class, 'update'])
        ->middleware('permission:currencies.manage')->where('code', $code)->name('currencies.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("currencies/{code}/{$action}", [CurrencyController::class, $action])
            ->middleware('permission:currencies.manage')->where('code', $code)->name("currencies.{$action}");
    }

    Route::get('rate-types', [RateTypeController::class, 'index'])->middleware('permission:exchange-rates.read')->name('rate-types.index');
    Route::post('rate-types', [RateTypeController::class, 'store'])->middleware('permission:exchange-rates.manage')->name('rate-types.store');
    Route::get('rate-types/{id}', [RateTypeController::class, 'show'])
        ->middleware('permission:exchange-rates.read')->where('id', ApiServiceProvider::ULID)->name('rate-types.show');
    Route::patch('rate-types/{id}', [RateTypeController::class, 'update'])
        ->middleware('permission:exchange-rates.manage')->where('id', ApiServiceProvider::ULID)->name('rate-types.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("rate-types/{id}/{$action}", [RateTypeController::class, $action])
            ->middleware('permission:exchange-rates.manage')->where('id', ApiServiceProvider::ULID)->name("rate-types.{$action}");
    }

    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:exchange-rates.read')->name('exchange-rates.index');
    Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->middleware('permission:exchange-rates.manage')->name('exchange-rates.store');
    Route::get('exchange-rates/lookup', [ExchangeRateController::class, 'lookup'])->middleware('permission:exchange-rates.read')->name('exchange-rates.lookup');
    Route::post('exchange-rates/import', [ExchangeRateController::class, 'import'])->middleware('permission:exchange-rates.manage')->name('exchange-rates.import');
    Route::get('exchange-rates/{id}', [ExchangeRateController::class, 'show'])
        ->middleware('permission:exchange-rates.read')->where('id', ApiServiceProvider::ULID)->name('exchange-rates.show');
});
