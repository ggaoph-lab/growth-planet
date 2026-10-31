<?php

use App\Modules\Game\Http\Controllers\Kid\GameLevelRewardController;
use App\Modules\Game\Http\Controllers\Kid\SchulteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::post('games/{game}/start', [GameLevelRewardController::class, 'start'])
        ->whereIn('game', ['schulte', 'mirror', 'sudoku'])->middleware('idempotent');
    Route::get('games/schulte', [SchulteController::class, 'show']);
    Route::post('games/schulte', [SchulteController::class, 'store'])->middleware('idempotent');
    Route::get('games/{game}', [GameLevelRewardController::class, 'show'])
        ->whereIn('game', ['mirror', 'sudoku']);
    Route::post('games/{game}', [GameLevelRewardController::class, 'store'])
        ->whereIn('game', ['mirror', 'sudoku'])
        ->middleware('idempotent');
});
