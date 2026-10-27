<?php

use App\Modules\Ledger\Http\Controllers\Kid\WalletController;
use App\Modules\Ledger\Http\Controllers\Parent\DeductionPresetController;
use App\Modules\Ledger\Http\Controllers\Parent\LedgerController;
use App\Modules\Ledger\Http\Controllers\Parent\RewardPolicyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('children/{id}/ledger', [LedgerController::class, 'showChildLedger'])->whereNumber('id');
    Route::post('ledger/{ulid}/reverse', [LedgerController::class, 'reverse'])->middleware(['sudo', 'idempotent']);
    Route::post('children/{id}/bonus', [LedgerController::class, 'bonus'])->whereNumber('id')->middleware(['sudo', 'idempotent']);
    Route::post('children/{id}/deduct', [LedgerController::class, 'deduct'])->whereNumber('id')->middleware(['sudo', 'idempotent']);

    Route::get('deduction-presets', [DeductionPresetController::class, 'index']);
    Route::post('deduction-presets', [DeductionPresetController::class, 'store'])->middleware('sudo');
    Route::put('deduction-presets/{id}', [DeductionPresetController::class, 'update'])->whereNumber('id')->middleware('sudo');
    Route::delete('deduction-presets/{id}', [DeductionPresetController::class, 'destroy'])->whereNumber('id')->middleware('sudo');

    Route::get('reward-policies', [RewardPolicyController::class, 'index']);
    Route::put('reward-policies', [RewardPolicyController::class, 'update'])->middleware('sudo');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('wallet/ledger', [WalletController::class, 'ledger']);
});
