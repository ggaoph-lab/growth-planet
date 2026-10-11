<?php

use App\Modules\Movie\Http\Controllers\Kid\MovieCollectionController as KidMovieCollectionController;
use App\Modules\Movie\Http\Controllers\Kid\MovieController as KidMovieController;
use App\Modules\Movie\Http\Controllers\Parent\MovieCollectionController;
use App\Modules\Movie\Http\Controllers\Parent\MovieController;
use App\Modules\ScreenTime\Http\Controllers\Kid\ScreenTimeController as KidScreenTimeController;
use App\Modules\ScreenTime\Http\Controllers\Parent\ScreenTimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('movies/external-search', [MovieController::class, 'externalSearch']);
    Route::get('movies/suggestions', [MovieController::class, 'suggestions']);
    Route::post('movies/suggestions/{key}/adopt', [MovieController::class, 'adoptSuggestion'])->where('key', '[a-z0-9-]+');
    Route::get('movie-posters/{key}', [MovieController::class, 'suggestionPoster'])->where('key', '[a-z0-9-]+');
    Route::get('movies/recommendations', [MovieCollectionController::class, 'recommendations']);
    Route::get('movies', [MovieController::class, 'index']);
    Route::get('movies/{id}/poster', [MovieController::class, 'poster'])->whereNumber('id');
    Route::post('movies', [MovieController::class, 'store']);
    Route::put('movies/{id}', [MovieController::class, 'update'])->whereNumber('id');
    Route::delete('movies/{id}', [MovieController::class, 'destroy'])->whereNumber('id');
    Route::post('movies/{id}/reviews', [MovieController::class, 'storeReview'])->whereNumber('id');
    Route::put('movies/{id}/approvals/{childId}', [MovieController::class, 'upsertApproval'])->whereNumber(['id', 'childId']);

    Route::get('movie-collections', [MovieCollectionController::class, 'index']);
    Route::post('movie-collections', [MovieCollectionController::class, 'store']);
    Route::get('movie-collections/{id}', [MovieCollectionController::class, 'show'])->whereNumber('id');
    Route::put('movie-collections/{id}', [MovieCollectionController::class, 'update'])->whereNumber('id');
    Route::delete('movie-collections/{id}', [MovieCollectionController::class, 'destroy'])->whereNumber('id');
    Route::put('movie-collections/{id}/items', [MovieCollectionController::class, 'syncItems'])->whereNumber('id');
    Route::post('movie-collections/{id}/merge', [MovieCollectionController::class, 'merge'])->whereNumber('id');
    Route::post('movie-collections/{id}/split', [MovieCollectionController::class, 'split'])->whereNumber('id');
    Route::post('movie-collections/{id}/sync-tmdb', [MovieCollectionController::class, 'syncTmdb'])->whereNumber('id');
    Route::put('movie-collections/{id}/preferences', [MovieCollectionController::class, 'setPreference'])->whereNumber('id');
    Route::post('movie-collections/{id}/approvals', [MovieCollectionController::class, 'batchApprovals'])->whereNumber('id');

    Route::get('screen-time/policies', [ScreenTimeController::class, 'policies']);
    Route::put('screen-time/policies', [ScreenTimeController::class, 'updatePolicies'])->middleware('sudo');
    Route::post('screen-time/adjustments', [ScreenTimeController::class, 'storeAdjustment'])->middleware('sudo');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('movies', [KidMovieController::class, 'index']);
    Route::get('movies/{id}', [KidMovieController::class, 'show'])->whereNumber('id');
    Route::get('movies/{id}/poster', [KidMovieController::class, 'poster'])->whereNumber('id');
    Route::get('movie-collections/{id}', [KidMovieCollectionController::class, 'show'])->whereNumber('id');
    Route::get('movie-library', [KidMovieController::class, 'library']);
    Route::post('movie-library/from/{movieId}', [KidMovieController::class, 'addLibrary'])->whereNumber('movieId')->middleware('idempotent');
    Route::post('movie-library/{id}/slot', [KidMovieController::class, 'assignSlot'])->whereNumber('id')->middleware('idempotent');
    Route::post('movie-library/{id}/sessions/start', [KidMovieController::class, 'startSession'])->whereNumber('id')->middleware('idempotent');
    Route::post('movie-library/{id}/sessions/stop', [KidMovieController::class, 'stopSession'])->whereNumber('id')->middleware('idempotent');
    Route::put('movie-library/{id}/progress', [KidMovieController::class, 'updateProgress'])->whereNumber('id');
    Route::put('movie-library/{id}/viewings', [KidMovieController::class, 'updateViewing'])->whereNumber('id');
    Route::post('movie-library/{id}/viewings', [KidMovieController::class, 'addViewing'])->whereNumber('id');
    Route::post('movie-library/{id}/viewings/{viewingId}/complete', [KidMovieController::class, 'completeViewing'])->whereNumber(['id', 'viewingId']);
    Route::post('movie-library/{id}/complete', [KidMovieController::class, 'complete'])->whereNumber('id')->middleware('idempotent');

    Route::get('screen-time', [KidScreenTimeController::class, 'show']);
});
