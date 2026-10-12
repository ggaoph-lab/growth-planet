<?php

use App\Modules\Identity\Http\Controllers\Auth\KidAuthController;
use App\Modules\Identity\Http\Controllers\Auth\ParentAuthController;
use App\Modules\Identity\Http\Controllers\Kid\HomeController;
use App\Modules\Identity\Http\Controllers\Parent\AuditLogController;
use App\Modules\Identity\Http\Controllers\Parent\ChildController;
use App\Modules\Identity\Http\Controllers\Parent\DashboardController;
use App\Modules\Identity\Http\Controllers\Parent\DeviceController;
use App\Modules\Identity\Http\Controllers\Parent\AiSettingsController;
use App\Modules\Identity\Http\Controllers\Parent\FamilyController;
use App\Modules\Identity\Http\Controllers\Parent\ParentAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::post('login', [ParentAuthController::class, 'login']);
    Route::get('device', [KidAuthController::class, 'device']);
    Route::get('kid/profiles', [KidAuthController::class, 'profiles']);
    Route::post('kid/login', [KidAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [ParentAuthController::class, 'logout']);
        Route::get('me', [ParentAuthController::class, 'me']);
        Route::post('confirm-password', [ParentAuthController::class, 'confirmPassword'])->middleware('role:parent');
    });
});

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('dashboard', DashboardController::class);

    Route::get('family', [FamilyController::class, 'show']);
    Route::patch('family', [FamilyController::class, 'update']);

    Route::get('parents', [ParentAccountController::class, 'index']);
    Route::post('parents', [ParentAccountController::class, 'store'])->middleware('sudo');
    Route::delete('parents/{id}', [ParentAccountController::class, 'destroy'])->whereNumber('id')->middleware('sudo');
    Route::patch('parents/me', [ParentAccountController::class, 'updateProfile']);
    Route::put('parents/me/password', [ParentAccountController::class, 'updatePassword'])->middleware('sudo');

    Route::get('ai-settings', [AiSettingsController::class, 'show']);
    Route::put('ai-settings', [AiSettingsController::class, 'update'])->middleware('sudo');

    Route::get('children', [ChildController::class, 'index']);
    Route::post('children', [ChildController::class, 'store']);
    Route::get('children/{id}', [ChildController::class, 'show'])->whereNumber('id');
    Route::patch('children/{id}', [ChildController::class, 'update'])->whereNumber('id');
    Route::put('children/{id}/birth-date', [ChildController::class, 'updateBirthDate'])->whereNumber('id')->middleware('sudo');
    Route::put('children/{id}/pin', [ChildController::class, 'updatePin'])->whereNumber('id')->middleware('sudo');

    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices', [DeviceController::class, 'store'])->middleware('sudo');
    Route::delete('devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id')->middleware('sudo');

    Route::get('audit-logs', [AuditLogController::class, 'index']);
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('home', [HomeController::class, 'show']);
    Route::patch('preferences', [HomeController::class, 'updatePreferences']);
});
