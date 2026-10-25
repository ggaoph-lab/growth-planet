<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use Illuminate\Database\QueryException;

class RewardGrantService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly XpLedgerService $xpLedger,
        private readonly CoinLedgerService $coinLedger,
        private readonly PointLedgerService $pointLedger,
    ) {}

    public function grant(RewardIntent $intent): RewardGrant
    {
        $existing = RewardGrant::query()->where('grant_key', $intent->grantKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $policy = RewardPolicy::query()
            ->where('family_id', $intent->familyId)
            ->where('code', $intent->policyCode)
            ->where('is_active', true)
            ->first();

        if ($policy === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('奖励策略不存在', 'Reward policy not found'), 404);
        }

        $xp = $intent->xpAmount ?? $policy->xp_amount;
        $coin = $intent->coinAmount ?? $policy->coin_amount;
        $point = $intent->pointAmount ?? $policy->point_amount;
        $capDetail = $this->applyCaps($intent, $policy, $xp, $coin, $point);
        $xp = $capDetail['xp'];
        $coin = $capDetail['coin'];
        $point = $capDetail['point'];

        $status = RewardGrantStatus::Granted;
        if ($intent->approvalRequestId !== null && ! $intent->postImmediately) {
            $status = RewardGrantStatus::PendingApproval;
        }
        if ($xp === 0 && $coin === 0 && $point === 0) {
            $status = RewardGrantStatus::Capped;
        }

        try {
            $grant = RewardGrant::query()->create([
                'family_id' => $intent->familyId,
                'child_id' => $intent->childId,
                'policy_code' => $intent->policyCode,
                'grant_key' => $intent->grantKey,
                'source_module' => $intent->sourceModule,
                'source_type' => $intent->sourceType,
                'source_id' => $intent->sourceId,
                'occurred_at' => $intent->occurredAt,
                'xp_amount' => $xp,
                'coin_amount' => $coin,
                'point_amount' => $point,
                'status' => $status,
                'approval_request_id' => $intent->approvalRequestId,
                'cap_detail' => $capDetail['detail'] ?: null,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return RewardGrant::query()->where('grant_key', $intent->grantKey)->firstOrFail();
            }
            throw $e;
        }

        if ($status === RewardGrantStatus::Granted && $intent->postImmediately) {
            $this->postLedgers($grant, $intent);
        }

        return $grant->fresh();
    }

    public function finalizeFromApproval(RewardGrant $grant, int $approvedBy): RewardGrant
    {
        if ($grant->status === RewardGrantStatus::Granted) {
            return $grant;
        }
        if ($grant->status !== RewardGrantStatus::PendingApproval) {
            throw new ApiException('INVALID_STATE', BiMsg::of('奖励状态不正确', 'Invalid reward status'), 409);
        }

        $family = Family::query()->findOrFail($grant->family_id);
        $businessDate = $this->clock->today($family->timezone)->toDateString();

        $intent = new RewardIntent(
            familyId: $grant->family_id,
            childId: $grant->child_id,
            policyCode: $grant->policy_code,
            grantKey: $grant->grant_key,
            sourceModule: $grant->source_module,
            sourceType: $grant->source_type,
            sourceId: $grant->source_id,
            occurredAt: $grant->occurred_at,
            xpAmount: $grant->xp_amount,
            coinAmount: $grant->coin_amount,
            pointAmount: $grant->point_amount,
            approvalRequestId: $grant->approval_request_id,
            approvedBy: $approvedBy,
            postImmediately: true,
        );

        $this->postLedgers($grant, $intent, $businessDate);
        $grant->status = $grant->xp_amount === 0 && $grant->coin_amount === 0 && $grant->point_amount === 0
            ? RewardGrantStatus::Capped
            : RewardGrantStatus::Granted;
        $grant->save();

        return $grant->fresh();
    }

    public function rejectGrant(RewardGrant $grant): RewardGrant
    {
        if ($grant->status === RewardGrantStatus::Rejected) {
            return $grant;
        }
        $grant->status = RewardGrantStatus::Rejected;
        $grant->save();

        return $grant;
    }

    /**
     * Undo a posted task reward and free its grant key so a later approval can pay again.
     */
    public function reversePosted(RewardGrant $grant, ?int $operatorId = null): RewardGrant
    {
        if ($grant->status === RewardGrantStatus::Reversed) {
            return $grant;
        }

        if ($grant->xp_ledger_id !== null) {
            $entry = XpLedgerEntry::query()->whereKey($grant->xp_ledger_id)->first();
            if ($entry !== null && $entry->reversal_of_id === null) {
                $this->xpLedger->reverse($entry, 'reversal:grant:'.$grant->id.':xp', $operatorId);
            }
        }

        if ($grant->coin_ledger_id !== null) {
            $entry = CoinLedgerEntry::query()->whereKey($grant->coin_ledger_id)->first();
            if ($entry !== null && $entry->reversal_of_id === null) {
                $this->coinLedger->reverse($entry, 'reversal:grant:'.$grant->id.':coin', $operatorId);
            }
        }

        if ($grant->point_ledger_id !== null) {
            $entry = PointLedgerEntry::query()->whereKey($grant->point_ledger_id)->first();
            if ($entry !== null && $entry->reversal_of_id === null) {
                $this->pointLedger->reverse($entry, 'reversal:grant:'.$grant->id.':point', $operatorId);
            }
        }

        $suffix = ':reversed:'.$grant->id;
        if (! str_ends_with($grant->grant_key, $suffix)) {
            $grant->grant_key = mb_substr($grant->grant_key, 0, 128 - strlen($suffix)).$suffix;
        }
        $grant->status = RewardGrantStatus::Reversed;
        $grant->save();

        return $grant->fresh();
    }

    /**
     * @return array{xp: int, coin: int, point: int, detail: array<string, mixed>}
     */
    private function applyCaps(RewardIntent $intent, RewardPolicy $policy, int $xp, int $coin, int $point): array
    {
        $detail = [];
        if ($policy->max_per_event !== null) {
            $coin = min($coin, $policy->max_per_event);
            $xp = min($xp, $policy->max_per_event);
            $point = min($point, $policy->max_per_event);
        }

        $family = Family::query()->findOrFail($intent->familyId);
        $today = $this->clock->today($family->timezone)->toDateString();
        [$weekStart] = $this->clock->weekRange($family->timezone);

        if ($policy->max_per_day !== null) {
            $dayEnd = $this->clock->today($family->timezone)->addDay()->toDateString();
            $dayGrants = fn () => RewardGrant::query()
                ->where('child_id', $intent->childId)
                ->where('policy_code', $intent->policyCode)
                ->whereIn('status', [RewardGrantStatus::Granted, RewardGrantStatus::Capped])
                ->whereDate('occurred_at', '>=', $today)
                ->whereDate('occurred_at', '<', $dayEnd);

            $xpRemaining = max(0, $policy->max_per_day - (int) $dayGrants()->sum('xp_amount'));
            if ($xp > $xpRemaining) {
                $detail['daily_xp_cap'] = ['requested' => $xp, 'granted' => $xpRemaining];
                $xp = $xpRemaining;
            }

            $coinRemaining = max(0, $policy->max_per_day - (int) $dayGrants()->sum('coin_amount'));
            if ($coin > $coinRemaining) {
                $detail['daily_coin_cap'] = ['requested' => $coin, 'granted' => $coinRemaining];
                $coin = $coinRemaining;
            }

            $pointRemaining = max(0, $policy->max_per_day - (int) $dayGrants()->sum('point_amount'));
            if ($point > $pointRemaining) {
                $detail['daily_point_cap'] = ['requested' => $point, 'granted' => $pointRemaining];
                $point = $pointRemaining;
            }
        }

        if ($policy->max_per_week !== null) {
            $weekGrants = fn () => RewardGrant::query()
                ->where('child_id', $intent->childId)
                ->where('policy_code', $intent->policyCode)
                ->whereIn('status', [RewardGrantStatus::Granted, RewardGrantStatus::Capped])
                ->where('occurred_at', '>=', $weekStart);

            $xpRemaining = max(0, $policy->max_per_week - (int) $weekGrants()->sum('xp_amount'));
            if ($xp > $xpRemaining) {
                $detail['weekly_xp_cap'] = ['requested' => $xp, 'granted' => $xpRemaining];
                $xp = $xpRemaining;
            }

            $coinRemaining = max(0, $policy->max_per_week - (int) $weekGrants()->sum('coin_amount'));
            if ($coin > $coinRemaining) {
                $detail['weekly_coin_cap'] = ['requested' => $coin, 'granted' => $coinRemaining];
                $coin = $coinRemaining;
            }

            $pointRemaining = max(0, $policy->max_per_week - (int) $weekGrants()->sum('point_amount'));
            if ($point > $pointRemaining) {
                $detail['weekly_point_cap'] = ['requested' => $point, 'granted' => $pointRemaining];
                $point = $pointRemaining;
            }
        }

        if ($policy->max_per_subject !== null && $intent->sourceId !== null) {
            $subjectCount = RewardGrant::query()
                ->where('child_id', $intent->childId)
                ->where('policy_code', $intent->policyCode)
                ->where('source_type', $intent->sourceType)
                ->where('source_id', $intent->sourceId)
                ->whereIn('status', [RewardGrantStatus::Granted, RewardGrantStatus::PendingApproval])
                ->count();
            if ($subjectCount >= $policy->max_per_subject) {
                $detail['max_per_subject'] = true;
                $xp = 0;
                $coin = 0;
                $point = 0;
            }
        }

        return ['xp' => $xp, 'coin' => $coin, 'point' => $point, 'detail' => $detail];
    }

    private function postLedgers(RewardGrant $grant, RewardIntent $intent, ?string $businessDate = null): void
    {
        if ($grant->xp_ledger_id !== null || $grant->coin_ledger_id !== null || $grant->point_ledger_id !== null) {
            return;
        }

        $family = Family::query()->findOrFail($grant->family_id);
        $businessDate ??= $this->clock->today($family->timezone)->toDateString();

        if ($grant->xp_amount > 0) {
            $xpAccount = XpAccount::query()->where('child_id', $grant->child_id)->firstOrFail();
            $xpEntry = $this->xpLedger->credit(
                $xpAccount,
                $grant->xp_amount,
                LedgerEntryType::Reward,
                $grant->source_module,
                $grant->source_type,
                $grant->source_id,
                'grant:'.$grant->id.':xp',
                $businessDate,
                $intent->approvedBy,
            );
            $grant->xp_ledger_id = $xpEntry->id;
        }

        if ($grant->coin_amount > 0) {
            $coinAccount = CoinAccount::query()->where('child_id', $grant->child_id)->firstOrFail();
            $coinEntry = $this->coinLedger->credit(
                $coinAccount,
                $grant->coin_amount,
                LedgerEntryType::Reward,
                $grant->source_module,
                $grant->source_type,
                $grant->source_id,
                'grant:'.$grant->id.':coin',
                $businessDate,
                $intent->approvedBy,
            );
            $grant->coin_ledger_id = $coinEntry->id;
        }

        if ($grant->point_amount > 0) {
            $pointAccount = PointAccount::query()->where('child_id', $grant->child_id)->firstOrFail();
            $pointEntry = $this->pointLedger->credit(
                $pointAccount,
                $grant->point_amount,
                LedgerEntryType::Reward,
                $grant->source_module,
                $grant->source_type,
                $grant->source_id,
                'grant:'.$grant->id.':point',
                $businessDate,
                $intent->approvedBy,
            );
            $grant->point_ledger_id = $pointEntry->id;
        }

        $grant->save();
    }
}
