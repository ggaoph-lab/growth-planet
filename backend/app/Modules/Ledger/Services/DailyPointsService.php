<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardGrant;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyPointsService
{
    public const POLICY_CODE = 'points.daily_allowance';

    public function __construct(
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly RewardGrantService $rewards,
        private readonly RewardPolicySeeder $policies,
    ) {}

    /**
     * Grant today's allowance for every active child (idempotent per child/day).
     *
     * @return array{granted: int, skipped: int, failed: int}
     */
    public function grantAll(): array
    {
        $granted = 0;
        $skipped = 0;
        $failed = 0;

        foreach (ChildProfile::with('family')->where('status', ChildStatus::Active->value)->lazyById(100) as $child) {
            try {
                $result = $this->grantForChild($child);
                if ($result === 'granted') {
                    $granted++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('points.daily_allowance_failed', [
                    'child_id' => $child->id,
                    'exception_type' => $e::class,
                ]);
            }
        }

        return compact('granted', 'skipped', 'failed');
    }

    /**
     * @return 'granted'|'skipped'
     */
    public function grantForChild(ChildProfile $child): string
    {
        $child->loadMissing('family');
        $family = $child->family;
        if ($family === null) {
            return 'skipped';
        }

        $this->policies->seedForFamily($family->id);

        $amount = (int) $this->settings->get('points.daily_allowance', $family->id);
        if ($amount <= 0) {
            return 'skipped';
        }

        $date = $this->clock->today($family->timezone)->toDateString();
        $grantKey = 'daily_points:'.$child->id.':'.$date;
        if (RewardGrant::query()->where('grant_key', $grantKey)->exists()) {
            return 'skipped';
        }

        $grant = $this->rewards->grant(new RewardIntent(
            familyId: $family->id,
            childId: $child->id,
            policyCode: self::POLICY_CODE,
            grantKey: $grantKey,
            sourceModule: SourceModule::System,
            sourceType: 'daily_allowance',
            sourceId: $child->id,
            occurredAt: $this->clock->now(),
            xpAmount: 0,
            coinAmount: 0,
            pointAmount: $amount,
            postImmediately: true,
        ));

        return $grant->point_amount > 0 ? 'granted' : 'skipped';
    }
}
