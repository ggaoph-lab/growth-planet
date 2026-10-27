<?php

namespace App\Modules\Ledger\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\DeductionPreset;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\PointLedgerService;
use App\Modules\Ledger\Services\XpLedgerService;
use App\Modules\Ledger\Support\LedgerEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LedgerController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly XpLedgerService $xpLedger,
        private readonly CoinLedgerService $coinLedger,
        private readonly PointLedgerService $pointLedger,
        private readonly AuditLogger $audit,
    ) {}

    public function showChildLedger(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $asset = $request->query('asset', 'coin');
        if ($asset === 'xp') {
            $account = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
            $entries = XpLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get();

            return response()->json(['data' => [
                'asset' => 'xp',
                ...$this->xpLedger->progressPayload($account),
                'entries' => LedgerEntryPresenter::collection($entries, 'xp'),
            ]]);
        }

        if ($asset === 'point' || $asset === 'points') {
            $account = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
            $entries = PointLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get();

            return response()->json(['data' => [
                'asset' => 'point',
                'balance' => $account->balance,
                'held' => $account->held,
                'available' => $account->available(),
                'lifetime_earned' => $account->lifetime_earned,
                'entries' => LedgerEntryPresenter::collection($entries, 'point'),
            ]]);
        }

        $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        $entries = CoinLedgerEntry::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'asset' => 'coin',
            'balance' => $account->balance,
            'held' => $account->held,
            'available' => $account->available(),
            'lifetime_earned' => $account->lifetime_earned,
            'entries' => LedgerEntryPresenter::collection($entries, 'coin'),
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

            return response()->json(['data' => LedgerEntryPresenter::one($reversed, 'coin')]);
        }

        $point = PointLedgerEntry::query()->where('ulid', $ulid)->where('family_id', $familyId)->first();
        if ($point !== null) {
            $reversed = DB::transaction(fn () => $this->pointLedger->reverse(
                $point,
                'reversal:'.$point->ulid,
                $this->context->user()->id,
            ));

            return response()->json(['data' => LedgerEntryPresenter::one($reversed, 'point')]);
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

        $this->audit->log('ledger.bonus', $family->id, $child, [
            'xp' => $xp,
            'coin' => $coin,
            'point' => $point,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function deduct(int $id, Request $request): JsonResponse
    {
        $child = $this->context->findChild($id);
        $family = $this->context->family();
        $data = $request->validate([
            'preset_ids' => ['nullable', 'array', 'max:20'],
            'preset_ids.*' => [
                'integer',
                Rule::exists('ledger_deduction_presets', 'id')->where(fn ($q) => $q
                    ->where('family_id', $family->id)
                    ->where('is_active', true)),
            ],
            'coin' => ['nullable', 'integer', 'min:0', 'max:50'],
            'point' => ['nullable', 'integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'min:1', 'max:255'],
        ]);

        $presetIds = array_values(array_unique(array_map('intval', $data['preset_ids'] ?? [])));
        $manualCoin = (int) ($data['coin'] ?? 0);
        $manualPoint = (int) ($data['point'] ?? 0);
        $manualNote = isset($data['note']) ? trim((string) $data['note']) : '';

        /** @var list<array{preset_id: int|null, title: string, point: int, coin: int}> $items */
        $items = [];

        if ($presetIds !== []) {
            $presets = DeductionPreset::query()
                ->where('family_id', $family->id)
                ->where('is_active', true)
                ->whereIn('id', $presetIds)
                ->get()
                ->keyBy('id');
            foreach ($presetIds as $presetId) {
                $preset = $presets->get($presetId);
                if ($preset === null) {
                    throw new ApiException('VALIDATION_FAILED', BiMsg::of('扣分项无效或已停用', 'Invalid or inactive deduction preset'), 422);
                }
                if ($preset->point_amount <= 0 && $preset->coin_amount <= 0) {
                    continue;
                }
                $items[] = [
                    'preset_id' => $preset->id,
                    'title' => $preset->title,
                    'point' => (int) $preset->point_amount,
                    'coin' => (int) $preset->coin_amount,
                ];
            }
        }

        if ($manualPoint > 0 || $manualCoin > 0) {
            if ($manualNote === '') {
                throw new ApiException('VALIDATION_FAILED', BiMsg::of('自定义扣分请填写原因', 'Please enter a reason for a custom deduction'), 422);
            }
            $items[] = [
                'preset_id' => null,
                'title' => $manualNote,
                'point' => $manualPoint,
                'coin' => $manualCoin,
            ];
        }

        if ($items === []) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请选择扣分项或填写自定义扣分', 'Select presets or enter a custom deduction'), 422);
        }

        $totalPoint = array_sum(array_column($items, 'point'));
        $totalCoin = array_sum(array_column($items, 'coin'));
        if ($totalPoint > 200 || $totalCoin > 200) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('单次扣分合计过多', 'Total deduction is too large'), 422);
        }

        $businessDate = $this->clock->today($family->timezone)->toDateString();
        $keyBase = 'parent:deduct:'.$child->id.':'.$request->header('Idempotency-Key');
        $operatorId = $this->context->user()->id;

        DB::transaction(function () use ($child, $items, $businessDate, $keyBase, $operatorId, $totalPoint, $totalCoin) {
            if ($totalPoint > 0) {
                $points = PointAccount::query()->where('child_id', $child->id)->lockForUpdate()->firstOrFail();
                if ($points->available() < $totalPoint) {
                    throw new ApiException('INSUFFICIENT_POINTS', BiMsg::of('积分不足', 'Insufficient points'), 422);
                }
            }
            if ($totalCoin > 0) {
                $coins = CoinAccount::query()->where('child_id', $child->id)->lockForUpdate()->firstOrFail();
                if ($coins->available() < $totalCoin) {
                    throw new ApiException('INSUFFICIENT_COINS', BiMsg::of('金币不足', 'Insufficient coins'), 422);
                }
            }

            foreach ($items as $index => $item) {
                $sourceType = $item['preset_id'] !== null ? 'deduction_preset' : 'child_profile';
                $sourceId = $item['preset_id'] ?? $child->id;
                $suffix = $item['preset_id'] !== null ? 'preset:'.$item['preset_id'] : 'custom:'.$index;

                if ($item['point'] > 0) {
                    $account = PointAccount::query()->where('child_id', $child->id)->lockForUpdate()->firstOrFail();
                    $this->pointLedger->debit(
                        $account,
                        $item['point'],
                        LedgerEntryType::ParentDeduction,
                        SourceModule::Parent,
                        $sourceType,
                        $sourceId,
                        $keyBase.':'.$suffix.':point',
                        $businessDate,
                        $operatorId,
                        $operatorId,
                        $item['title'],
                    );
                }
                if ($item['coin'] > 0) {
                    $account = CoinAccount::query()->where('child_id', $child->id)->lockForUpdate()->firstOrFail();
                    $this->coinLedger->debit(
                        $account,
                        $item['coin'],
                        LedgerEntryType::ParentDeduction,
                        SourceModule::Parent,
                        $sourceType,
                        $sourceId,
                        $keyBase.':'.$suffix.':coin',
                        $businessDate,
                        $operatorId,
                        $operatorId,
                        $item['title'],
                    );
                }
            }
        });

        $this->audit->log('ledger.deduct', $family->id, $child, [
            'point' => $totalPoint,
            'coin' => $totalCoin,
            'preset_ids' => $presetIds,
            'items' => $items,
        ]);

        return response()->json(['data' => [
            'ok' => true,
            'point' => $totalPoint,
            'coin' => $totalCoin,
            'count' => count($items),
        ]], 201);
    }
}
