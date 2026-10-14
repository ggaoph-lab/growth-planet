<?php

use App\Modules\Item\Http\Controllers\Kid\BagController;
use App\Modules\Item\Http\Controllers\Kid\ItemChecklistController as KidItemChecklistController;
use App\Modules\Item\Http\Controllers\Kid\ItemEventController as KidItemEventController;
use App\Modules\Item\Http\Controllers\Kid\ItemStatusController;
use App\Modules\Item\Http\Controllers\Parent\ItemChecklistController;
use App\Modules\Item\Http\Controllers\Parent\ItemController;
use App\Modules\Item\Http\Controllers\Parent\ItemEventController;
use App\Modules\Item\Http\Controllers\Parent\ItemLocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('item-locations', [ItemLocationController::class, 'index']);
    Route::post('item-locations', [ItemLocationController::class, 'store']);
    Route::put('item-locations/{id}', [ItemLocationController::class, 'update'])->whereNumber('id');
    Route::delete('item-locations/{id}', [ItemLocationController::class, 'destroy'])->whereNumber('id');

    Route::get('items', [ItemController::class, 'index']);
    Route::post('items', [ItemController::class, 'store']);
    Route::put('items/{id}', [ItemController::class, 'update'])->whereNumber('id');
    Route::delete('items/{id}', [ItemController::class, 'destroy'])->whereNumber('id');

    Route::get('items/{id}/events', [ItemEventController::class, 'index'])->whereNumber('id');
    Route::post('items/{id}/events', [ItemEventController::class, 'store'])->whereNumber('id')->middleware('idempotent');

    Route::get('item-checklists', [ItemChecklistController::class, 'index']);
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('bag', [BagController::class, 'show']);
    Route::post('item-checklists/{id}/lines/{lid}', [KidItemChecklistController::class, 'updateLine'])
        ->whereNumber(['id', 'lid'])
        ->middleware('idempotent');
    Route::post('item-checklists/{id}/brought-items', [KidItemChecklistController::class, 'addBroughtItem'])
        ->whereNumber('id')
        ->middleware('idempotent');
    Route::post('item-checklists/{id}/complete', [KidItemChecklistController::class, 'complete'])
        ->whereNumber('id')
        ->middleware('idempotent');
    Route::post('item-checklists/{id}/reopen', [KidItemChecklistController::class, 'reopen'])
        ->whereNumber('id')
        ->middleware('idempotent');
    Route::get('items/{id}/events', [KidItemEventController::class, 'index'])->whereNumber('id');
    Route::post('items/{id}/report-missing', [ItemStatusController::class, 'reportMissing'])->whereNumber('id')->middleware('idempotent');
    Route::post('items/{id}/report-found', [ItemStatusController::class, 'reportFound'])->whereNumber('id')->middleware('idempotent');
});
