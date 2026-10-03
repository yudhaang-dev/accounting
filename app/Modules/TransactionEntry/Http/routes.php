<?php

use App\Modules\TransactionEntry\Http\EntryAttachmentController;
use App\Modules\TransactionEntry\Http\EntryController;
use App\Modules\TransactionEntry\Http\JournalReversalController;
use App\Modules\TransactionEntry\Http\RecurringScheduleController;
use App\Modules\TransactionEntry\Http\TransactionTypeController;
use App\Modules\TransactionEntry\Http\TransactionTypeTemplateController;
use App\Modules\TransactionEntry\Http\TransactionTypeVersionController;
use App\Platform\Api\ApiServiceProvider;
use Illuminate\Support\Facades\Route;

// API routes for the TransactionEntry module, mounted under /api/v1 (see TransactionEntryServiceProvider).

Route::companyScoped(function () {
    Route::get('transaction-types', [TransactionTypeController::class, 'index'])->middleware('permission:entries.read|transaction-types.read')->name('transaction-types.index');
    Route::get('transaction-type-templates', [TransactionTypeTemplateController::class, 'index'])
        ->middleware('permission:policies.manage')->name('transaction-type-templates.index');
    Route::get('transaction-type-templates/{key}', [TransactionTypeTemplateController::class, 'show'])
        ->middleware('permission:policies.manage')->where('key', '[a-z0-9-]+')->name('transaction-type-templates.show');
    Route::post('transaction-types/import-template', [TransactionTypeTemplateController::class, 'import'])
        ->middleware('permission:policies.manage')->name('transaction-types.import-template');
    Route::post('transaction-types', [TransactionTypeController::class, 'store'])->middleware('permission:policies.manage')->name('transaction-types.store');
    Route::get('transaction-types/{id}', [TransactionTypeController::class, 'show'])
        ->middleware('permission:entries.read|transaction-types.read')->where('id', ApiServiceProvider::ULID)->name('transaction-types.show');

    Route::put('transaction-types/{id}/numbering', [TransactionTypeController::class, 'numbering'])
        ->middleware('permission:policies.manage')->where('id', ApiServiceProvider::ULID)->name('transaction-types.numbering');

    Route::post('transaction-types/{id}/versions', [TransactionTypeVersionController::class, 'store'])
        ->middleware('permission:policies.manage')->where('id', ApiServiceProvider::ULID)->name('transaction-types.versions.store');
    Route::get('transaction-types/{id}/versions/{version}/schema', [TransactionTypeVersionController::class, 'schema'])
        ->middleware('permission:entries.read|transaction-types.read')->where('id', ApiServiceProvider::ULID)->whereNumber('version')->name('transaction-types.versions.schema');
    Route::put('transaction-types/{id}/versions/{version}', [TransactionTypeVersionController::class, 'update'])
        ->middleware('permission:policies.manage')->where('id', ApiServiceProvider::ULID)->whereNumber('version')->name('transaction-types.versions.update');
    Route::post('transaction-types/{id}/versions/{version}/publish', [TransactionTypeVersionController::class, 'publish'])
        ->middleware('permission:policies.manage')->where('id', ApiServiceProvider::ULID)->whereNumber('version')->name('transaction-types.versions.publish');
    Route::post('transaction-types/{id}/versions/{version}/retire', [TransactionTypeVersionController::class, 'retire'])
        ->middleware('permission:policies.manage')->where('id', ApiServiceProvider::ULID)->whereNumber('version')->name('transaction-types.versions.retire');

    Route::get('entries', [EntryController::class, 'index'])->middleware('permission:entries.read')->name('entries.index');
    Route::post('entries', [EntryController::class, 'store'])->middleware('permission:entries.create')->name('entries.store');
    Route::get('entries/{id}', [EntryController::class, 'show'])
        ->middleware('permission:entries.read')->where('id', ApiServiceProvider::ULID)->name('entries.show');
    Route::patch('entries/{id}', [EntryController::class, 'update'])
        ->middleware('permission:entries.create')->where('id', ApiServiceProvider::ULID)->name('entries.update');

    Route::get('entries/{id}/attachments', [EntryAttachmentController::class, 'index'])
        ->middleware('permission:entries.read')->where('id', ApiServiceProvider::ULID)->name('entries.attachments.index');
    Route::post('entries/{id}/attachments', [EntryAttachmentController::class, 'store'])
        ->middleware('permission:entries.create')->where('id', ApiServiceProvider::ULID)->name('entries.attachments.store');
    Route::get('entries/{id}/attachments/{attachmentId}', [EntryAttachmentController::class, 'download'])
        ->middleware('permission:entries.read')->where('id', ApiServiceProvider::ULID)->where('attachmentId', ApiServiceProvider::ULID)->name('entries.attachments.download');
    Route::delete('entries/{id}/attachments/{attachmentId}', [EntryAttachmentController::class, 'destroy'])
        ->middleware('permission:entries.create')->where('id', ApiServiceProvider::ULID)->where('attachmentId', ApiServiceProvider::ULID)->name('entries.attachments.destroy');

    Route::get('recurring-schedules', [RecurringScheduleController::class, 'index'])->middleware('permission:recurring-schedules.read')->name('recurring-schedules.index');
    Route::post('recurring-schedules', [RecurringScheduleController::class, 'store'])->middleware('permission:recurring-schedules.manage')->name('recurring-schedules.store');
    Route::get('recurring-schedules/{id}', [RecurringScheduleController::class, 'show'])
        ->middleware('permission:recurring-schedules.read')->where('id', ApiServiceProvider::ULID)->name('recurring-schedules.show');
    Route::put('recurring-schedules/{id}', [RecurringScheduleController::class, 'update'])
        ->middleware('permission:recurring-schedules.manage')->where('id', ApiServiceProvider::ULID)->name('recurring-schedules.update');
    Route::post('recurring-schedules/{id}/skip', [RecurringScheduleController::class, 'skip'])
        ->middleware('permission:recurring-schedules.manage')->where('id', ApiServiceProvider::ULID)->name('recurring-schedules.skip');

    Route::post('journals/{id}/reverse', [JournalReversalController::class, 'store'])
        ->middleware('permission:entries.reverse')->where('id', ApiServiceProvider::ULID)->name('journals.reverse');

    $permissions = [
        'submit' => 'entries.submit',
        'approve' => 'entries.approve',
        'reject' => 'entries.reject',
        'post' => 'entries.post',
        'revise' => 'entries.create',
        'cancel' => 'entries.create',
    ];

    foreach ($permissions as $action => $permission) {
        Route::post("entries/{id}/{$action}", [EntryController::class, $action])
            ->middleware("permission:{$permission}")->where('id', ApiServiceProvider::ULID)->name("entries.{$action}");
    }
});
