<?php

use App\Modules\EnglishBeta\EnglishBetaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/kid/english-beta')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('', [EnglishBetaController::class, 'home']);
    Route::post('words/lookup', [EnglishBetaController::class, 'lookupWord'])->middleware('throttle:30,1');
    Route::post('sessions', [EnglishBetaController::class, 'start'])->middleware('throttle:10,1');
    Route::post('sessions/{id}/listen', [EnglishBetaController::class, 'listen'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('sessions/{id}/transcribe', [EnglishBetaController::class, 'transcribe'])->whereNumber('id')->middleware('throttle:6,1');
    Route::post('sessions/{id}/turns', [EnglishBetaController::class, 'turn'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('sessions/{id}/hint', [EnglishBetaController::class, 'hint'])->whereNumber('id')->middleware('throttle:8,1');
    Route::post('sessions/{id}/finish', [EnglishBetaController::class, 'finish'])->whereNumber('id')->middleware('throttle:10,1');
});
Route::prefix('v1/parent/english-beta')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('', [EnglishBetaController::class, 'report']);
    Route::put('settings', [EnglishBetaController::class, 'settings']);
});
