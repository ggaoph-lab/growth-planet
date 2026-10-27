<?php

use App\Modules\Poetry\Http\Controllers\Kid\PoetryController as KidPoetryController;
use App\Modules\Poetry\Http\Controllers\Parent\PoetryController as ParentPoetryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/kid/poetry')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('', [KidPoetryController::class, 'home']);
    Route::get('required', [KidPoetryController::class, 'required']);
    Route::get('recommend', [KidPoetryController::class, 'recommend']);
    Route::get('challenge', [KidPoetryController::class, 'challenge']);
    Route::get('poems/{id}', [KidPoetryController::class, 'show'])->whereNumber('id');
    Route::get('poems/{id}/audio', [KidPoetryController::class, 'audio'])
        ->whereNumber('id')
        ->middleware('throttle:30,1');
    Route::post('poems/{id}/audio/warm', [KidPoetryController::class, 'warmAudio'])
        ->whereNumber('id')
        ->middleware('throttle:6,1');
    Route::post('poems/{id}/read-aloud', [KidPoetryController::class, 'readAloud'])
        ->whereNumber('id')
        ->middleware(['throttle:20,1', 'idempotent']);
    Route::post('poems/{id}/transcribe', [KidPoetryController::class, 'transcribe'])
        ->whereNumber('id')
        ->middleware('throttle:12,1');
    Route::post('poems/{id}/recite', [KidPoetryController::class, 'recite'])
        ->whereNumber('id')
        ->middleware('throttle:20,1');
});

Route::prefix('v1/parent/poetry')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('', [ParentPoetryController::class, 'index']);
    Route::put('settings', [ParentPoetryController::class, 'settings']);
    Route::get('search', [ParentPoetryController::class, 'search'])->middleware('throttle:30,1');
    Route::post('assignments', [ParentPoetryController::class, 'assign'])->middleware('throttle:30,1');
    Route::delete('assignments/{id}', [ParentPoetryController::class, 'cancelAssignment'])->whereNumber('id');
});
