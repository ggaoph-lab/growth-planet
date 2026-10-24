<?php

use App\Modules\Encyclopedia\Http\Controllers\Kid\EncyclopediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('encyclopedia', [EncyclopediaController::class, 'index']);
    Route::get('encyclopedia/domains/{domain}', [EncyclopediaController::class, 'domain'])
        ->where('domain', '[A-Za-z0-9%-]+');
    Route::get('encyclopedia/topics/{id}', [EncyclopediaController::class, 'show'])
        ->where('id', 'mt_[A-Za-z0-9_-]+');
    Route::post('encyclopedia/topics/{id}/read', [EncyclopediaController::class, 'markRead'])
        ->where('id', 'mt_[A-Za-z0-9_-]+')
        ->middleware('idempotent');
    Route::post('encyclopedia/topics/{id}/words', [EncyclopediaController::class, 'collectWord'])
        ->where('id', 'mt_[A-Za-z0-9_-]+')
        ->middleware('throttle:60,1');
});
