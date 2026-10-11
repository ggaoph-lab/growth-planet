<?php

use App\Modules\Report\Http\Controllers\Kid\CollectionController;
use App\Modules\Report\Http\Controllers\Parent\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('reports/daily', [ReportController::class, 'daily']);
    Route::get('reports/weekly', [ReportController::class, 'weekly']);
    Route::get('reports/growth', [ReportController::class, 'growth']);
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('collection', [CollectionController::class, 'index']);
});
