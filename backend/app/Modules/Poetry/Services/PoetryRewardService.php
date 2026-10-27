<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;

class PoetryRewardService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
    ) {}

    /**
     * @return array{xp: int, points: int, coins: int, capped: bool}|null
     */
    public function grant(
        ChildProfile $child,
        string $policyCode,
        string $grantKey,
        string $sourceType,
        int $sourceId,
    ): ?array {
        $policy = RewardPolicy::query()->firstOrCreate(
            ['family_id' => $child->family_id, 'code' => $policyCode],
            match ($policyCode) {
                'poetry.read_aloud' => [
                    'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 2,
                    'requires_approval' => false, 'max_per_day' => 10, 'is_active' => true,
                ],
                'poetry.review' => [
                    'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 3,
                    'requires_approval' => false, 'max_per_day' => 15, 'is_active' => true,
                ],
                'poetry.challenge' => [
                    'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 3,
                    'requires_approval' => false, 'max_per_day' => 10, 'is_active' => true,
                ],
                default => [
                    'xp_amount' => 2, 'coin_amount' => 0, 'point_amount' => 5,
                    'requires_approval' => false, 'max_per_day' => 10, 'is_active' => true,
                ],
            },
        );

        if (! $policy->is_active || $policy->requires_approval) {
            return null;
        }

        $grant = $this->rewards->grant(new RewardIntent(
            familyId: $child->family_id,
            childId: $child->id,
            policyCode: $policyCode,
            grantKey: $grantKey,
            sourceModule: SourceModule::Poetry,
            sourceType: $sourceType,
            sourceId: $sourceId,
            occurredAt: $this->clock->now(),
            postImmediately: true,
        ));

        $xp = (int) $grant->xp_amount;
        $points = (int) $grant->point_amount;
        $coins = (int) $grant->coin_amount;

        return [
            'xp' => $xp,
            'points' => $points,
            'coins' => $coins,
            'capped' => $xp === 0 && $points === 0 && $coins === 0,
        ];
    }
}
