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
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\PointLedgerService;
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
        private readonly PointLedgerService $pointLedger,
    ) {}

    public function showChildLedger(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $asset = $request->query('asset', 'coin');
        if ($asset === 'xp') {
            $account = XpAccount::query()->where('child_id', $child->id)->firstOrFail();

            return response()->json(['data' => [
                'asset' => 'xp',
                ...$this->xpLedger->progressPayload($account),
                'entries' => XpLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get(),
            ]]);
        }

        if ($asset === 'point' || $asset === 'points') {
            $account = PointAccount::query()->where('child_id', $child->id)->firstOrFail();

            return response()->json(['data' => [
                'asset' => 'point',
                'balance' => $account->balance,
                'held' => $account->held,
                'available' => $account->available(),
                'lifetime_earned' => $account->lifetime_earned,
                'entries' => PointLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get(),
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
        $familyId = $this->context->familyId();
        $coin = CoinLedgerEntry::query()->where('ulid', $ulid)->where('family_id', $familyId)->first();
        if ($coin !== null) {
            $reversed = DB::transaction(fn () => $this->coinLedger->reverse(
                $coin,
                'reversal:'.$coin->ulid,
                $this->context->user()->id,
            ));

            return response()->json(['data' => $reversed]);
        }

        $point = PointLedgerEntry::query()->where('ulid', $ulid)->where('family_id', $familyId)->first();
        if ($point !== null) {
            $reversed = DB::transaction(fn () => $this->pointLedger->reverse(
                $point,
                'reversal:'.$point->ulid,
                $this->context->user()->id,
            ));

            return response()->json(['data' => $reversed]);
        }

        throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
    }

    public function bonus(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $data = $request->validate([
            'xp' => ['nullable', 'integer', 'min:0', 'max:50'],
            'coin' => ['nullable', 'integer', 'min:0', 'max:50'],
            'point' => ['nullable', 'integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $xp = (int) ($data['xp'] ?? 0);
        $coin = (int) ($data['coin'] ?? 0);
        $point = (int) ($data['point'] ?? 0);
        if ($xp === 0 && $coin === 0 && $point === 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请填写奖励数量', 'Please enter a reward amount'), 422);
        }

        $family = $this->context->family();
        $businessDate = $this->clock->today($family->timezone)->toDateString();
        $keyBase = 'parent:bonus:'.$child->id.':'.$request->header('Idempotency-Key');

        DB::transaction(function () use ($child, $xp, $coin, $point, $businessDate, $keyBase, $data) {
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
            if ($point > 0) {
                $account = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
                $this->pointLedger->credit(
                    $account,
                    $point,
                    LedgerEntryType::ParentBonus,
                    SourceModule::Parent,
                    'child_profile',
                    $child->id,
                    $keyBase.':point',
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
