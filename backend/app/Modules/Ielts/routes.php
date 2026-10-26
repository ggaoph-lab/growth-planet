<?php

use App\Modules\Ielts\Http\Controllers\Kid\IeltsRewardController;
use App\Modules\Ielts\Http\Controllers\Parent\IeltsSettingsController;
use Illuminate\Support\Facades\Route;

// 雅思星球 (Word Atlas, served at /ielts/) settles each finished learning session for points/coins.
Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::post('ielts/sessions/settle', [IeltsRewardController::class, 'settle'])
        ->middleware(['idempotent', 'throttle:30,1']);
});

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('ielts/settings', [IeltsSettingsController::class, 'show']);
    Route::put('ielts/settings', [IeltsSettingsController::class, 'update']);
});
