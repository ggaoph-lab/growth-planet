<?php

namespace App\Modules\Ledger\Http\Controllers\Kid;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Services\AccountProvisioner;
use App\Modules\Ledger\Services\XpLedgerService;
use Illuminate\Http\JsonResponse;

class WalletController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly XpLedgerService $xpLedger,
        private readonly AccountProvisioner $accounts,
        private readonly Clock $clock,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $this->accounts->forChild($child);

        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();

        // Existing XP earned before levels existed stays on the bar until the next credit;
        // reconcile here so the wallet never shows a full bar stuck at level 1.
        $family = $child->family ?? $this->context->family();
        $this->xpLedger->reconcileLevels($xp, $this->clock->today($family->timezone)->toDateString());
        $xp = $xp->fresh() ?? $xp;

        return response()->json(['data' => [
            'xp' => $this->xpLedger->progressPayload($xp),
            'points' => [
                'balance' => $points->balance,
                'held' => $points->held,
                'available' => $points->available(),
                'lifetime_earned' => $points->lifetime_earned,
            ],
            'coin' => [
                'balance' => $coin->balance,
                'held' => $coin->held,
                'available' => $coin->available(),
                'lifetime_earned' => $coin->lifetime_earned,
            ],
        ]]);
    }
}
