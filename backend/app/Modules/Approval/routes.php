<?php

use App\Modules\Approval\Http\Controllers\Kid\RequestsController;
use App\Modules\Approval\Http\Controllers\Parent\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::get('approvals/{id}', [ApprovalController::class, 'show'])->whereNumber('id');
    Route::get('approvals/{id}/events', [ApprovalController::class, 'events'])->whereNumber('id');
    Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id')->middleware('idempotent');
    Route::post('approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id')->middleware('idempotent');
    Route::post('approvals/{id}/return', [ApprovalController::class, 'returnRequest'])->whereNumber('id')->middleware('idempotent');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('requests', [RequestsController::class, 'index']);
});
