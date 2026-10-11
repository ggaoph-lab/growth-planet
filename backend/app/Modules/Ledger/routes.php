<?php

use App\Modules\Ledger\Http\Controllers\Kid\WalletController;
use App\Modules\Ledger\Http\Controllers\Parent\LedgerController;
use App\Modules\Ledger\Http\Controllers\Parent\RewardPolicyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/parent')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
    Route::get('children/{id}/ledger', [LedgerController::class, 'showChildLedger'])->whereNumber('id');
    Route::post('ledger/{ulid}/reverse', [LedgerController::class, 'reverse'])->middleware(['sudo', 'idempotent']);
    Route::post('children/{id}/bonus', [LedgerController::class, 'bonus'])->whereNumber('id')->middleware(['sudo', 'idempotent']);

    Route::get('reward-policies', [RewardPolicyController::class, 'index']);
    Route::put('reward-policies', [RewardPolicyController::class, 'update'])->middleware('sudo');
});

Route::prefix('v1/kid')->middleware(['auth:sanctum', 'role:child'])->group(function () {
    Route::get('wallet', [WalletController::class, 'show']);
});
