<?php

use App\Modules\Habit\Http\Controllers\Kid\HabitController as KidHabitController;
use App\Modules\Habit\Http\Controllers\Parent\HabitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('habits', [HabitController::class, 'index']);
    Route::post('habits', [HabitController::class, 'store']);
    Route::put('habits/{id}', [HabitController::class, 'update'])->whereNumber('id');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('habits', [KidHabitController::class, 'index']);
    Route::post('habits/{id}/records', [KidHabitController::class, 'storeRecord'])->whereNumber('id')->middleware('idempotent');
});
