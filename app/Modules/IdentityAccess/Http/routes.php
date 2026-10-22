<?php

use App\Modules\IdentityAccess\Http\IntegrationClientController;
use App\Modules\IdentityAccess\Http\InvitationController;
use App\Modules\IdentityAccess\Http\MeController;
use App\Modules\IdentityAccess\Http\MembershipController;
use App\Modules\IdentityAccess\Http\PermissionController;
use App\Modules\IdentityAccess\Http\RoleController;
use App\Modules\IdentityAccess\Http\SecurityPolicyController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

// API routes for the IdentityAccess module, mounted under /api/v1 (see IdentityAccessServiceProvider).

Route::get('me', [MeController::class, 'show'])->middleware('auth')->name('me');
Route::get('me/workspaces', [MeController::class, 'workspaces'])->middleware('auth')->name('me.workspaces');

// Client Credentials token request of integration clients (API-TR-22): form-encoded, `client_secret_basic`.
Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])->name('oauth.token');

Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept');

Route::companyScoped(function () {
    $ulid = ApiServiceProvider::ULID;

    Route::get('memberships', [MembershipController::class, 'index'])->middleware('permission:memberships.read')->name('memberships.index');
    Route::get('memberships/{id}', [MembershipController::class, 'show'])
        ->middleware('permission:memberships.read')->where('id', $ulid)->name('memberships.show');
    Route::patch('memberships/{id}', [MembershipController::class, 'update'])
        ->middleware('permission:memberships.manage')->where('id', $ulid)->name('memberships.update');
    Route::post('memberships/{id}/revoke', [MembershipController::class, 'revoke'])
        ->middleware('permission:memberships.manage')->where('id', $ulid)->name('memberships.revoke');

    Route::get('invitations', [InvitationController::class, 'index'])->middleware('permission:memberships.read')->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('permission:memberships.manage')->name('invitations.store');
    Route::post('invitations/{id}/revoke', [InvitationController::class, 'revoke'])
        ->middleware('permission:memberships.manage')->where('id', $ulid)->name('invitations.revoke');

    Route::get('integration-clients', [IntegrationClientController::class, 'index'])->middleware('permission:integration-clients.manage')->name('integration-clients.index');
    Route::post('integration-clients', [IntegrationClientController::class, 'store'])->middleware('permission:integration-clients.manage')->name('integration-clients.store');
    Route::get('integration-clients/{id}', [IntegrationClientController::class, 'show'])
        ->middleware('permission:integration-clients.manage')->where('id', $ulid)->name('integration-clients.show');
    Route::patch('integration-clients/{id}', [IntegrationClientController::class, 'update'])
        ->middleware('permission:integration-clients.manage')->where('id', $ulid)->name('integration-clients.update');
    Route::post('integration-clients/{id}/rotate-secret', [IntegrationClientController::class, 'rotateSecret'])
        ->middleware('permission:integration-clients.manage')->where('id', $ulid)->name('integration-clients.rotate-secret');
    Route::post('integration-clients/{id}/rotate-webhook-secret', [IntegrationClientController::class, 'rotateWebhookSecret'])
        ->middleware('permission:integration-clients.manage')->where('id', $ulid)->name('integration-clients.rotate-webhook-secret');
    Route::post('integration-clients/{id}/revoke', [IntegrationClientController::class, 'revoke'])
        ->middleware('permission:integration-clients.manage')->where('id', $ulid)->name('integration-clients.revoke');

    Route::get('security-policy', [SecurityPolicyController::class, 'show'])->middleware('permission:policies.manage')->name('security-policy.show');
    Route::put('security-policy', [SecurityPolicyController::class, 'update'])->middleware('permission:policies.manage')->name('security-policy.update');

    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.read')->name('permissions.index');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.read')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
    Route::get('roles/{id}', [RoleController::class, 'show'])
        ->middleware('permission:roles.read')->where('id', ApiServiceProvider::ULID)->name('roles.show');
    Route::patch('roles/{id}', [RoleController::class, 'update'])
        ->middleware('permission:roles.manage')->where('id', ApiServiceProvider::ULID)->name('roles.update');

    foreach (['deactivate', 'reactivate'] as $action) {
        Route::post("roles/{id}/{$action}", [RoleController::class, $action])
            ->middleware('permission:roles.manage')->where('id', ApiServiceProvider::ULID)->name("roles.{$action}");
    }
});
