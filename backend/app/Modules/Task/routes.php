<?php

use App\Modules\Task\Http\Controllers\Kid\TaskController as KidTaskController;
use App\Modules\Task\Http\Controllers\Kid\TtsController as KidTtsController;
use App\Modules\Task\Http\Controllers\Parent\HomeworkController;
use App\Modules\Task\Http\Controllers\Parent\TaskCategoryController;
use App\Modules\Task\Http\Controllers\Parent\TaskController;
use App\Modules\Task\Http\Controllers\Parent\TaskInstanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('task-categories', [TaskCategoryController::class, 'index']);
    Route::post('task-categories', [TaskCategoryController::class, 'store']);
    Route::put('task-categories/{id}', [TaskCategoryController::class, 'update'])->whereNumber('id');
    Route::delete('task-categories/{id}', [TaskCategoryController::class, 'destroy'])->whereNumber('id');

    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::put('tasks/{id}', [TaskController::class, 'update'])->whereNumber('id');
    Route::delete('tasks/{id}', [TaskController::class, 'destroy'])->whereNumber('id');

    Route::post('homework/parse', [HomeworkController::class, 'parse']);
    Route::post('homework/confirm', [HomeworkController::class, 'confirm']);

    Route::get('task-instances', [TaskInstanceController::class, 'index']);
    Route::post('task-instances/{id}/approve', [TaskInstanceController::class, 'approve'])->whereNumber('id')->middleware('idempotent');
    Route::post('task-instances/{id}/return', [TaskInstanceController::class, 'returnInstance'])->whereNumber('id')->middleware('idempotent');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('tasks', [KidTaskController::class, 'index']);
    Route::post('tts', [KidTtsController::class, 'speak']);
    Route::post('task-instances/{id}/ready', [KidTaskController::class, 'ready'])->whereNumber('id')->middleware('idempotent');
    Route::post('task-instances/{id}/first-step', [KidTaskController::class, 'firstStep'])->whereNumber('id')->middleware('idempotent');
    Route::post('task-instances/{id}/steps/{sid}/check', [KidTaskController::class, 'checkStep'])->whereNumber(['id', 'sid'])->middleware('idempotent');
    Route::post('task-instances/{id}/self-check', [KidTaskController::class, 'selfCheck'])->whereNumber('id')->middleware('idempotent');
    Route::post('task-instances/{id}/submit', [KidTaskController::class, 'submit'])->whereNumber('id')->middleware('idempotent');
});
