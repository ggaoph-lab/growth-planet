<?php

namespace App\Modules\Ledger\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\XpAccount;
use Illuminate\Http\JsonResponse;

class WalletController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();

        return response()->json(['data' => [
            'xp' => ['balance' => $xp->balance, 'lifetime_earned' => $xp->lifetime_earned],
            'coin' => [
                'balance' => $coin->balance,
                'held' => $coin->held,
                'available' => $coin->available(),
                'lifetime_earned' => $coin->lifetime_earned,
            ],
        ]]);
    }
}
