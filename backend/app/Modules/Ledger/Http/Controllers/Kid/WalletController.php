<?php

namespace App\Modules\Ledger\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Ledger\Services\AccountProvisioner;
use App\Modules\Ledger\Services\XpLedgerService;
use App\Modules\Ledger\Support\LedgerEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly XpLedgerService $xpLedger,
        private readonly AccountProvisioner $accounts,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $this->accounts->forChild($child);

        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();

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

    public function ledger(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $this->accounts->forChild($child);
        $asset = (string) $request->query('asset', 'point');

        if ($asset === 'xp') {
            $account = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
            $entries = XpLedgerEntry::query()
                ->where('account_id', $account->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            return response()->json(['data' => [
                'asset' => 'xp',
                ...$this->xpLedger->progressPayload($account),
                'entries' => LedgerEntryPresenter::collection($entries, 'xp'),
            ]]);
        }

        if ($asset === 'coin') {
            $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
            $entries = CoinLedgerEntry::query()
                ->where('account_id', $account->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            return response()->json(['data' => [
                'asset' => 'coin',
                'balance' => $account->balance,
                'held' => $account->held,
                'available' => $account->available(),
                'lifetime_earned' => $account->lifetime_earned,
                'entries' => LedgerEntryPresenter::collection($entries, 'coin'),
            ]]);
        }

        if ($asset === 'point' || $asset === 'points') {
            $account = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
            $entries = PointLedgerEntry::query()
                ->where('account_id', $account->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            return response()->json(['data' => [
                'asset' => 'point',
                'balance' => $account->balance,
                'held' => $account->held,
                'available' => $account->available(),
                'lifetime_earned' => $account->lifetime_earned,
                'entries' => LedgerEntryPresenter::collection($entries, 'point'),
            ]]);
        }

        throw new ApiException('VALIDATION_FAILED', BiMsg::of('资产类型无效', 'Invalid asset type'), 422);
    }
}
