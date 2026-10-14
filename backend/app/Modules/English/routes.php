<?php

use App\Modules\English\Http\Controllers\Kid\EnglishController as KidEnglishController;
use App\Modules\English\Http\Controllers\Kid\EnglishLearningController;
use App\Modules\English\Http\Controllers\Parent\EnglishController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('english/shows', [EnglishController::class, 'indexShows']);
    Route::post('english/shows', [EnglishController::class, 'storeShow']);
    Route::patch('english/shows/{id}', [EnglishController::class, 'updateShow'])->whereNumber('id');
    Route::get('english/logs', [EnglishController::class, 'indexLogs']);
    Route::get('english/learning', [EnglishController::class, 'learning']);
    Route::get('english/learning/summary', [EnglishController::class, 'learningSummary']);
    Route::put('english/learning/path', [EnglishController::class, 'updateLearningPath']);
    Route::get('english/learning/drafts', [EnglishController::class, 'indexDrafts']);
    Route::post('english/learning/drafts/generate', [EnglishController::class, 'generateDraft'])->middleware('throttle:20,1');
    Route::post('english/learning/drafts/{id}/publish', [EnglishController::class, 'publishDraft'])->whereNumber('id');
    Route::post('english/learning/drafts/{id}/reject', [EnglishController::class, 'rejectDraft'])->whereNumber('id');
    Route::get('english/learning/speaks', [EnglishController::class, 'indexSpeaks']);
    Route::get('english/learning/speaks/{id}/audio', [EnglishController::class, 'speakAudio'])->whereNumber('id');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('english/learning', [EnglishLearningController::class, 'index']);
    Route::post('english/learning/answer', [EnglishLearningController::class, 'answer'])->middleware(['idempotent', 'throttle:60,1']);
    Route::post('english/learning/words', [EnglishLearningController::class, 'addWord'])->middleware('throttle:60,1');
    Route::get('english/learning/records', [EnglishLearningController::class, 'records']);
    Route::get('english/learning/fairy-tale', [EnglishLearningController::class, 'fairyTale']);
    Route::post('english/learning/fairy-tale/heard', [EnglishLearningController::class, 'fairyTaleHeard'])->middleware(['idempotent', 'throttle:30,1']);
    Route::post('english/learning/fairy-tale/words', [EnglishLearningController::class, 'fairyTaleWord'])->middleware('throttle:60,1');
    Route::get('english/learning/speak', [EnglishLearningController::class, 'speakPrompt']);
    Route::post('english/learning/speak', [EnglishLearningController::class, 'speakSubmit'])->middleware(['idempotent', 'throttle:20,1']);
    Route::get('english/shows', [KidEnglishController::class, 'indexShows']);
    Route::get('english/logs', [KidEnglishController::class, 'indexLogs']);
    Route::post('english/logs', [KidEnglishController::class, 'storeLog'])->middleware('idempotent');
    Route::delete('english/logs/{id}', [KidEnglishController::class, 'destroyLog'])->whereNumber('id');
});
