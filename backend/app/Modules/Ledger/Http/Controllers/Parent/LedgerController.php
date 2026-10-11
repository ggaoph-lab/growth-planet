<?php

namespace App\Modules\Ledger\Http\Controllers\Parent;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\XpLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LedgerController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly XpLedgerService $xpLedger,
        private readonly CoinLedgerService $coinLedger,
    ) {}

    public function showChildLedger(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $asset = $request->query('asset', 'coin');
        if ($asset === 'xp') {
            $account = XpAccount::query()->where('child_id', $child->id)->firstOrFail();

            return response()->json(['data' => [
                'asset' => 'xp',
                'balance' => $account->balance,
                'lifetime_earned' => $account->lifetime_earned,
                'entries' => XpLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get(),
            ]]);
        }

        $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();

        return response()->json(['data' => [
            'asset' => 'coin',
            'balance' => $account->balance,
            'held' => $account->held,
            'available' => $account->available(),
            'lifetime_earned' => $account->lifetime_earned,
            'entries' => CoinLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get(),
        ]]);
    }

    public function reverse(string $ulid): JsonResponse
    {
        $entry = CoinLedgerEntry::query()
            ->where('ulid', $ulid)
            ->where('family_id', $this->context->familyId())
            ->firstOrFail();

        $reversed = DB::transaction(fn () => $this->coinLedger->reverse(
            $entry,
            'reversal:'.$entry->ulid,
            $this->context->user()->id,
        ));

        return response()->json(['data' => $reversed]);
    }

    public function bonus(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $data = $request->validate([
            'xp' => ['nullable', 'integer', 'min:0', 'max:50'],
            'coin' => ['nullable', 'integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $xp = (int) ($data['xp'] ?? 0);
        $coin = (int) ($data['coin'] ?? 0);
        if ($xp === 0 && $coin === 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请填写奖励数量', 'Please enter a reward amount'), 422);
        }

        $family = $this->context->family();
        $businessDate = $this->clock->today($family->timezone)->toDateString();
        $keyBase = 'parent:bonus:'.$child->id.':'.$request->header('Idempotency-Key');

        DB::transaction(function () use ($child, $xp, $coin, $businessDate, $keyBase, $data) {
            if ($xp > 0) {
                $account = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
                $this->xpLedger->credit(
                    $account,
                    $xp,
                    LedgerEntryType::ParentBonus,
                    SourceModule::Parent,
                    'child_profile',
                    $child->id,
                    $keyBase.':xp',
                    $businessDate,
                    $this->context->user()->id,
                    $this->context->user()->id,
                    $data['note'] ?? null,
                );
            }
            if ($coin > 0) {
                $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
                $this->coinLedger->credit(
                    $account,
                    $coin,
                    LedgerEntryType::ParentBonus,
                    SourceModule::Parent,
                    'child_profile',
                    $child->id,
                    $keyBase.':coin',
                    $businessDate,
                    $this->context->user()->id,
                    $this->context->user()->id,
                    $data['note'] ?? null,
                );
            }
        });

        return response()->json(['data' => ['ok' => true]], 201);
    }
}
