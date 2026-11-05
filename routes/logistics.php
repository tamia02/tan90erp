<?php

use App\Http\Controllers\LogisticsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('logistics')->name('logistics.')->group(function () {
    Route::get('/', [LogisticsController::class, 'index'])->name('index');
    Route::get('create', [LogisticsController::class, 'create'])->name('create');
    Route::get('export', [LogisticsController::class, 'export'])->name('export');
    Route::post('rtd', [LogisticsController::class, 'store'])->name('store');
    Route::post('vehicle-types', [LogisticsController::class, 'vehicleType'])->name('vehicle-types.store');
    Route::post('documents/{document}/refresh', [LogisticsController::class, 'refreshDocument'])->middleware('throttle:20,1')->name('documents.refresh');
    Route::get('attachments/{attachment}', [LogisticsController::class, 'download'])->name('attachments.download');
    Route::get('{shipment}', [LogisticsController::class, 'show'])->whereNumber('shipment')->name('show');
    Route::post('{shipment}/charges/{charge}/void', [LogisticsController::class, 'voidCharge'])->whereNumber('shipment')->whereNumber('charge')->name('charges.void');
    Route::post('{shipment}/{action}', [LogisticsController::class, 'action'])->whereNumber('shipment')->whereIn('action', ['quote', 'plan', 'transition', 'edd', 'charge', 'completion', 'attachment'])->name('action');
});
