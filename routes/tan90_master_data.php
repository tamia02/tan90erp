<?php

use App\Http\Controllers\Tan90\MasterData\ApprovalQueueController;
use App\Http\Controllers\Tan90\MasterData\AttachmentController;
use App\Http\Controllers\Tan90\MasterData\AuditTrailController;
use App\Http\Controllers\Tan90\MasterData\ChangeRequestController;
use App\Http\Controllers\Tan90\MasterData\DashboardController;
use App\Http\Controllers\Tan90\MasterData\DataImportController;
use App\Http\Controllers\Tan90\MasterData\DataQualityController;
use App\Http\Controllers\Tan90\MasterData\GstVerificationController;
use App\Http\Controllers\Tan90\MasterData\IntegrationConnectionController;
use App\Http\Controllers\Tan90\MasterData\MasterDataController;
use App\Http\Controllers\Tan90\MasterData\ModuleSettingsController;
use App\Http\Controllers\Tan90\MasterData\PermissionMatrixController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tan90 Master Data & Configuration routes
|--------------------------------------------------------------------------
| All routes require an authenticated session (the 'auth' middleware alias
| from the host app). Register this file's provider in
| bootstrap/providers.php - see docs/INSTALL.md - rather than including it
| directly from routes/web.php.
*/

Route::middleware(['web', 'auth'])->prefix('tan90/master-data')->name('tan90.master-data.')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('approval-queue', [ApprovalQueueController::class, 'index'])->name('approval-queue');

    Route::get('change-requests', [ChangeRequestController::class, 'index'])->name('change-requests.index');
    Route::get('change-requests/{changeRequest}', [ChangeRequestController::class, 'show'])->name('change-requests.show');
    Route::post('change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])->name('change-requests.approve');
    Route::post('change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])->name('change-requests.reject');

    Route::get('permission-matrix', [PermissionMatrixController::class, 'edit'])->name('permission-matrix.edit');
    Route::post('permission-matrix', [PermissionMatrixController::class, 'update'])->name('permission-matrix.update');

    Route::get('settings/{group?}', [ModuleSettingsController::class, 'edit'])->name('settings.edit');
    Route::post('settings/{group}', [ModuleSettingsController::class, 'update'])->name('settings.update');

    Route::get('audit-logs', [AuditTrailController::class, 'index'])->name('audit-logs');

    Route::get('import', [DataImportController::class, 'index'])->name('import.index');
    Route::post('import', [DataImportController::class, 'upload'])->name('import.upload');
    Route::get('import/{job}', [DataImportController::class, 'show'])->name('import.show');
    Route::post('import/{job}/commit', [DataImportController::class, 'commit'])->name('import.commit');
    Route::get('import/{job}/rejected.csv', [DataImportController::class, 'rejectedCsv'])->name('import.rejected-csv');

    Route::get('data-quality', [DataQualityController::class, 'index'])->name('data-quality.index');
    Route::post('data-quality/scan', [DataQualityController::class, 'scan'])->name('data-quality.scan');
    Route::post('data-quality/{issue}/resolve', [DataQualityController::class, 'resolve'])->name('data-quality.resolve');

    Route::post('integration-connections/{connection}/test', [IntegrationConnectionController::class, 'test'])->name('integration-connections.test');

    // Registered before the generic {entity}/{id} routes: "DELETE attachments/{id}"
    // would otherwise be swallowed by "DELETE {entity}/{id}" with entity=attachments.
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // Generic entity CRUD + governance actions, per the Codex prompt's required route pattern.
    Route::get('{entity}', [MasterDataController::class, 'index'])->name('index');
    Route::get('{entity}/create', [MasterDataController::class, 'create'])->name('create');
    Route::post('{entity}', [MasterDataController::class, 'store'])->name('store');
    Route::get('{entity}/export', [MasterDataController::class, 'export'])->name('export');
    Route::get('{entity}/{id}', [MasterDataController::class, 'show'])->whereNumber('id')->name('show');
    Route::get('{entity}/{id}/edit', [MasterDataController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('{entity}/{id}', [MasterDataController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('{entity}/{id}', [MasterDataController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('{entity}/{id}/restore', [MasterDataController::class, 'restore'])->whereNumber('id')->name('restore');
    Route::post('{entity}/{id}/submit', [MasterDataController::class, 'submit'])->whereNumber('id')->name('submit');
    Route::post('{entity}/{id}/approve', [MasterDataController::class, 'approve'])->whereNumber('id')->name('approve');
    Route::post('{entity}/{id}/reject', [MasterDataController::class, 'reject'])->whereNumber('id')->name('reject');
    Route::post('{entity}/{id}/verify-gst', [GstVerificationController::class, 'verify'])->whereNumber('id')->name('verify-gst');
    Route::post('{entity}/{id}/attachments', [AttachmentController::class, 'store'])->whereNumber('id')->name('attachments.store');
});
