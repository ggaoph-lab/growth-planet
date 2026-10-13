<?php

use App\Modules\English\Http\Controllers\Kid\EnglishController as KidEnglishController;
use App\Modules\English\Http\Controllers\Parent\EnglishController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('english/shows', [EnglishController::class, 'indexShows']);
    Route::post('english/shows', [EnglishController::class, 'storeShow']);
    Route::patch('english/shows/{id}', [EnglishController::class, 'updateShow'])->whereNumber('id');
    Route::get('english/logs', [EnglishController::class, 'indexLogs']);
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('english/learning', [\App\Modules\English\Http\Controllers\Kid\EnglishLearningController::class, 'index']);
    Route::post('english/learning/answer', [\App\Modules\English\Http\Controllers\Kid\EnglishLearningController::class, 'answer'])->middleware(['idempotent', 'throttle:60,1']);
    Route::get('english/shows', [KidEnglishController::class, 'indexShows']);
    Route::get('english/logs', [KidEnglishController::class, 'indexLogs']);
    Route::post('english/logs', [KidEnglishController::class, 'storeLog'])->middleware('idempotent');
    Route::delete('english/logs/{id}', [KidEnglishController::class, 'destroyLog'])->whereNumber('id');
});
