<?php

use App\Modules\Exploration\Http\Controllers\Kid\ExplorationController as KidExplorationController;
use App\Modules\Exploration\Http\Controllers\Parent\ExplorationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('explorations', [ExplorationController::class, 'index']);
    Route::post('explorations', [ExplorationController::class, 'store']);
    Route::put('explorations/{id}', [ExplorationController::class, 'update'])->whereNumber('id');
    Route::post('explorations/{id}/events', [ExplorationController::class, 'storeEvent'])->whereNumber('id')->middleware('idempotent');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('explorations', [KidExplorationController::class, 'index']);
    Route::post('explorations', [KidExplorationController::class, 'store'])->middleware('idempotent');
    Route::post('explorations/{id}/events', [KidExplorationController::class, 'storeEvent'])->whereNumber('id')->middleware('idempotent');
});
