<?php

use App\Modules\Schedule\Http\Controllers\Kid\ScheduleController as KidScheduleController;
use App\Modules\Schedule\Http\Controllers\Parent\BagExtraRequirementController;
use App\Modules\Schedule\Http\Controllers\Parent\ScheduleExceptionController;
use App\Modules\Schedule\Http\Controllers\Parent\SchoolScheduleController;
use App\Modules\Schedule\Http\Controllers\Parent\SubjectItemRequirementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('children/{id}/school-schedule', [SchoolScheduleController::class, 'show'])->whereNumber('id');
    Route::put('children/{id}/school-schedule', [SchoolScheduleController::class, 'update'])->whereNumber('id');
    Route::get('children/{id}/schedule', [SchoolScheduleController::class, 'resolveDay'])->whereNumber('id');

    Route::get('schedule-exceptions', [ScheduleExceptionController::class, 'index']);
    Route::post('schedule-exceptions', [ScheduleExceptionController::class, 'store']);
    Route::put('schedule-exceptions/{id}', [ScheduleExceptionController::class, 'update'])->whereNumber('id');
    Route::delete('schedule-exceptions/{id}', [ScheduleExceptionController::class, 'destroy'])->whereNumber('id');

    Route::get('subject-item-requirements', [SubjectItemRequirementController::class, 'index']);
    Route::post('subject-item-requirements', [SubjectItemRequirementController::class, 'store']);
    Route::delete('subject-item-requirements/{id}', [SubjectItemRequirementController::class, 'destroy'])->whereNumber('id');

    Route::get('bag-extra-requirements', [BagExtraRequirementController::class, 'index']);
    Route::post('bag-extra-requirements', [BagExtraRequirementController::class, 'store']);
    Route::delete('bag-extra-requirements/{id}', [BagExtraRequirementController::class, 'destroy'])->whereNumber('id');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('schedule', [KidScheduleController::class, 'show']);
    Route::get('schedule/week', [KidScheduleController::class, 'week']);
});
