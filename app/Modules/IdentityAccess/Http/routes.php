<?php

use App\Modules\IdentityAccess\Http\MeController;
use Illuminate\Support\Facades\Route;

// API routes for the IdentityAccess module, mounted under /api/v1 (see IdentityAccessServiceProvider).

Route::get('me', [MeController::class, 'show'])->middleware('auth')->name('me');
Route::get('me/workspaces', [MeController::class, 'workspaces'])->middleware('auth')->name('me.workspaces');
