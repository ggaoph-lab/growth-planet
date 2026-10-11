<?php

use App\Modules\Food\Http\Controllers\Kid\FoodController as KidFoodController;
use App\Modules\Food\Http\Controllers\Parent\FoodController;
use App\Modules\Food\Http\Controllers\Parent\FoodRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('foods', [FoodController::class, 'index']);
    Route::post('foods', [FoodController::class, 'store']);
    Route::put('foods/{id}', [FoodController::class, 'update'])->whereNumber('id');

    Route::get('food-records', [FoodRecordController::class, 'index']);
    Route::post('food-records', [FoodRecordController::class, 'store']);
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('foods', [KidFoodController::class, 'indexFoods']);
    Route::post('food-records', [KidFoodController::class, 'storeRecord'])->middleware('idempotent');
});
