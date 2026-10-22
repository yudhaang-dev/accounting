<?php

use App\Modules\Consolidation\Http\GroupAccountController;
use App\Modules\Consolidation\Http\GroupController;
use App\Modules\Consolidation\Http\MappingController;
use App\Modules\Consolidation\Http\MemberAccountController;
use App\Modules\Consolidation\Http\PerimeterController;
use App\Modules\Consolidation\Http\RunController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the Consolidation module, mounted under /api/v1 (see ConsolidationServiceProvider). A group
// belongs to no company, so these routes are not company-scoped: access is a grant on the group (IA-FR-07).
// Creating a group, listing them and proposing a member are in Orchestration, as they need the consent of the company.

Route::prefix('groups/{group}')->where(['group' => ApiServiceProvider::ULID, 'id' => ApiServiceProvider::ULID])->middleware(['auth', 'idempotent'])->group(function () {
    Route::get('/', [GroupController::class, 'show'])->middleware('group-access:read')->name('groups.show');
    Route::patch('/', [GroupController::class, 'update'])->middleware('group-access:run')->name('groups.update');

    Route::get('accounts', [GroupAccountController::class, 'index'])->middleware('group-access:read')->name('groups.accounts.index');
    Route::post('accounts', [GroupAccountController::class, 'store'])->middleware('group-access:run')->name('groups.accounts.store');
    Route::patch('accounts/{id}', [GroupAccountController::class, 'update'])->middleware('group-access:run')->name('groups.accounts.update');

    Route::get('perimeter', [PerimeterController::class, 'index'])->middleware('group-access:read')->name('groups.perimeter.index');
    Route::post('perimeter/{id}/approve', [PerimeterController::class, 'approve'])->middleware('group-access:approve')->name('groups.perimeter.approve');
    Route::post('perimeter/{id}/end', [PerimeterController::class, 'end'])->middleware('group-access:run')->name('groups.perimeter.end');

    Route::get('member-accounts', [MemberAccountController::class, 'index'])->middleware('group-access:read')->name('groups.member-accounts.index');
    Route::get('account-mappings', [MappingController::class, 'show'])->middleware('group-access:read')->name('groups.account-mappings.show');
    Route::put('account-mappings', [MappingController::class, 'update'])->middleware('group-access:run')->name('groups.account-mappings.update');

    Route::get('runs', [RunController::class, 'index'])->middleware('group-access:read')->name('groups.runs.index');
    Route::post('runs', [RunController::class, 'store'])->middleware('group-access:run')->name('groups.runs.store');
    Route::get('runs/{id}', [RunController::class, 'show'])->middleware('group-access:read')->name('groups.runs.show');
});
