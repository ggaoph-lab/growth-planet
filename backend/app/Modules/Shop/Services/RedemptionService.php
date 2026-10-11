<?php

namespace App\Modules\Shop\Services;

use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinHold;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Shop\Enums\RedemptionStatus;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Models\RewardRedemption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class RedemptionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CoinLedgerService $coins,
        private readonly ApprovalService $approvals,
    ) {}

    public function request(Reward $reward, ChildProfile $child, Family $family, string $idempotencyKey): RewardRedemption
    {
        $existing = RewardRedemption::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($reward->family_id !== $family->id || $reward->status !== RewardStatus::OnShelf) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
        if ($reward->stock !== null && $reward->stock < 1) {
            throw new ApiException('LIMIT_REACHED', BiMsg::of('商品已兑完', 'This item is sold out'), 422);
        }

        $coinAccount = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        $snapshot = [
            'name' => $reward->name,
            'kind' => $reward->kind->value,
            'coin_price' => $reward->coin_price,
        ];

        try {
            $redemption = RewardRedemption::query()->create([
                'ulid' => (string) Str::ulid(),
                'family_id' => $family->id,
                'child_id' => $child->id,
                'reward_id' => $reward->id,
                'reward_snapshot' => $snapshot,
                'coin_price' => $reward->coin_price,
                'status' => RedemptionStatus::Requested,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return RewardRedemption::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        $hold = $this->coins->hold(
            $coinAccount,
            $reward->coin_price,
            'reward_redemption',
            $redemption->id,
            'redemption:'.$redemption->ulid.':hold',
        );
        $redemption->coin_hold_id = $hold->id;
        $redemption->save();

        if ($reward->requires_approval) {
            $approval = $this->approvals->createPending(
                $family->id,
                $child->id,
                ApprovalType::Redemption,
                'reward_redemption',
                $redemption->id,
                $child->member->user_id,
            );
            $redemption->approval_request_id = $approval->id;
            $redemption->save();
        } else {
            $this->finalizeApproved($redemption, $family, null);
        }

        return $redemption->fresh();
    }

    public function cancel(RewardRedemption $redemption, ChildProfile $child): RewardRedemption
    {
        if ($redemption->child_id !== $child->id) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
        if ($redemption->status !== RedemptionStatus::Requested) {
            throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能取消', 'Cannot cancel in the current state'), 409);
        }

        if ($redemption->coin_hold_id) {
            $hold = CoinHold::query()->findOrFail($redemption->coin_hold_id);
            $this->coins->release($hold);
        }
        $redemption->status = RedemptionStatus::Cancelled;
        $redemption->save();

        return $redemption;
    }

    public function finalizeApproved(RewardRedemption $redemption, Family $family, ?int $approvedBy): void
    {
        if ($redemption->status === RedemptionStatus::Approved) {
            return;
        }

        $hold = CoinHold::query()->findOrFail($redemption->coin_hold_id);
        $businessDate = $this->clock->today($family->timezone)->toDateString();
        $entry = $this->coins->capture(
            $hold,
            'redemption:'.$redemption->ulid.':debit',
            $businessDate,
            $approvedBy,
        );
        $redemption->coin_ledger_id = $entry->id;
        $redemption->status = RedemptionStatus::Approved;
        $redemption->save();

        $reward = Reward::query()->whereKey($redemption->reward_id)->lockForUpdate()->first();
        if ($reward && $reward->stock !== null) {
            $reward->stock = max(0, $reward->stock - 1);
            $reward->save();
        }
    }

    public function finalizeRejected(RewardRedemption $redemption): void
    {
        if ($redemption->status === RedemptionStatus::Rejected) {
            return;
        }
        if ($redemption->coin_hold_id) {
            $hold = CoinHold::query()->findOrFail($redemption->coin_hold_id);
            $this->coins->release($hold);
        }
        $redemption->status = RedemptionStatus::Rejected;
        $redemption->save();
    }
}
