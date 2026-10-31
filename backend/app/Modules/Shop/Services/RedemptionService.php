<?php

namespace App\Modules\Shop\Services;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinHold;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointHold;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\PointLedgerService;
use App\Modules\Shop\Enums\RedemptionStatus;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Models\RewardRedemption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RedemptionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CoinLedgerService $coins,
        private readonly PointLedgerService $points,
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

        $price = $reward->priceAmount();
        if ($price < 1) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('商品价格无效', 'Invalid reward price'), 422);
        }

        $usePoints = $reward->pricedInPoints();
        $snapshot = [
            'name' => $reward->name,
            'game_minutes' => $reward->game_minutes,
            'kind' => $reward->kind->value,
            'currency' => $usePoints ? 'point' : 'coin',
            'coin_price' => (int) ($reward->coin_price ?? 0),
            'point_price' => (int) ($reward->point_price ?? 0),
            'price' => $price,
        ];

        try {
            $redemption = RewardRedemption::query()->create([
                'ulid' => (string) Str::ulid(),
                'family_id' => $family->id,
                'child_id' => $child->id,
                'reward_id' => $reward->id,
                'reward_snapshot' => $snapshot,
                'coin_price' => $usePoints ? 0 : $price,
                'point_price' => $usePoints ? $price : null,
                'status' => RedemptionStatus::Requested,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return RewardRedemption::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        if ($usePoints) {
            $account = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
            $hold = $this->points->hold(
                $account,
                $price,
                'reward_redemption',
                $redemption->id,
                'redemption:'.$redemption->ulid.':hold',
            );
            $redemption->point_hold_id = $hold->id;
        } else {
            $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
            $hold = $this->coins->hold(
                $account,
                $price,
                'reward_redemption',
                $redemption->id,
                'redemption:'.$redemption->ulid.':hold',
            );
            $redemption->coin_hold_id = $hold->id;
        }
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
        return DB::transaction(function () use ($redemption, $child) {
            $redemption = RewardRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            if ($redemption->child_id !== $child->id) {
                throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
            }
            if ($redemption->status !== RedemptionStatus::Requested) {
                throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能取消', 'Cannot cancel in the current state'), 409);
            }

            $this->releaseHold($redemption);
            $redemption->status = RedemptionStatus::Cancelled;
            $redemption->save();

            if ($redemption->approval_request_id) {
                $approval = ApprovalRequest::query()->whereKey($redemption->approval_request_id)->lockForUpdate()->first();
                if ($approval !== null && $approval->status === ApprovalStatus::Pending) {
                    $child->loadMissing('member');
                    $this->approvals->cancel($approval, $child->member?->user_id);
                }
            }

            return $redemption;
        });
    }

    /**
     * Parent writes off a pending redemption: capture the hold and mark it delivered.
     */
    public function fulfill(RewardRedemption $redemption, Family $family, ?int $approvedBy): void
    {
        if ($redemption->status === RedemptionStatus::Fulfilled) {
            return;
        }

        $this->finalizeApproved($redemption, $family, $approvedBy);

        if ($redemption->status !== RedemptionStatus::Approved) {
            return;
        }

        $redemption->status = RedemptionStatus::Fulfilled;
        $redemption->save();
    }

    public function finalizeApproved(RewardRedemption $redemption, Family $family, ?int $approvedBy): void
    {
        if ($redemption->status === RedemptionStatus::Approved) {
            return;
        }

        $businessDate = $this->clock->today($family->timezone)->toDateString();

        if ($redemption->point_hold_id) {
            $hold = PointHold::query()->findOrFail($redemption->point_hold_id);
            $entry = $this->points->capture(
                $hold,
                'redemption:'.$redemption->ulid.':debit',
                $businessDate,
                $approvedBy,
            );
            $redemption->point_ledger_id = $entry->id;
        } else {
            $hold = CoinHold::query()->findOrFail($redemption->coin_hold_id);
            $entry = $this->coins->capture(
                $hold,
                'redemption:'.$redemption->ulid.':debit',
                $businessDate,
                $approvedBy,
            );
            $redemption->coin_ledger_id = $entry->id;
        }

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
        $this->releaseHold($redemption);
        $redemption->status = RedemptionStatus::Rejected;
        $redemption->save();
    }

    private function releaseHold(RewardRedemption $redemption): void
    {
        if ($redemption->point_hold_id) {
            $hold = PointHold::query()->findOrFail($redemption->point_hold_id);
            $this->points->release($hold);

            return;
        }
        if ($redemption->coin_hold_id) {
            $hold = CoinHold::query()->findOrFail($redemption->coin_hold_id);
            $this->coins->release($hold);
        }
    }
}
