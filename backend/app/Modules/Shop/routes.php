<?php

use App\Modules\Shop\Http\Controllers\Kid\ShopController;
use App\Modules\Shop\Http\Controllers\Kid\WishController as KidWishController;
use App\Modules\Shop\Http\Controllers\Parent\RedemptionController;
use App\Modules\Shop\Http\Controllers\Parent\RewardController;
use App\Modules\Shop\Http\Controllers\Parent\WishController as ParentWishController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('rewards', [RewardController::class, 'index']);
    Route::post('rewards', [RewardController::class, 'store']);
    Route::put('rewards/{id}', [RewardController::class, 'update'])->whereNumber('id');
    Route::post('rewards/{id}/image', [RewardController::class, 'uploadImage'])->whereNumber('id');
    Route::delete('rewards/{id}/image', [RewardController::class, 'destroyImage'])->whereNumber('id');
    Route::get('rewards/{id}/image', [RewardController::class, 'image'])->whereNumber('id');
    Route::delete('rewards/{id}', [RewardController::class, 'destroy'])->whereNumber('id');
    Route::get('redemptions', [RedemptionController::class, 'index']);
    Route::get('wishes', [ParentWishController::class, 'index']);
    Route::post('wishes/{id}/reject', [ParentWishController::class, 'reject'])->whereNumber('id')->middleware('idempotent');
    Route::post('wishes/{id}/convert', [ParentWishController::class, 'convert'])->whereNumber('id')->middleware('idempotent');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('shop', [ShopController::class, 'index']);
    Route::get('shop/{id}/image', [ShopController::class, 'image'])->whereNumber('id');
    Route::get('wishes', [KidWishController::class, 'index']);
    Route::post('wishes', [KidWishController::class, 'store'])->middleware('idempotent');
    Route::post('redemptions', [ShopController::class, 'redeem'])->middleware('idempotent');
    Route::post('redemptions/{ulid}/cancel', [ShopController::class, 'cancel'])->middleware('idempotent');
});
