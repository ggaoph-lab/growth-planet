<?php

namespace App\Modules\Encyclopedia\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Encyclopedia\Models\EncyclopediaRead;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EncyclopediaProgress
{
    public const POLICY_CODE = 'encyclopedia.read';

    public const REWARD_DAILY_LIMIT = 10;

    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
    ) {}

    /**
     * @return list<string>
     */
    public function readTopicIds(ChildProfile $child): array
    {
        return EncyclopediaRead::query()
            ->where('child_id', $child->id)
            ->pluck('topic_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Mark a topic read and grant the encyclopedia reward once per topic.
     *
     * @return array{read: EncyclopediaRead, reward: array{xp: int, points: int, capped: bool}|null}
     */
    public function markRead(ChildProfile $child, string $topicId): array
    {
        return DB::transaction(function () use ($child, $topicId) {
            $existing = EncyclopediaRead::query()
                ->where('child_id', $child->id)
                ->where('topic_id', $topicId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['read' => $existing, 'reward' => null];
            }

            $now = CarbonImmutable::now('UTC');
            $read = EncyclopediaRead::query()->create([
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'topic_id' => $topicId,
                'read_at' => $now,
            ]);

            return [
                'read' => $read,
                'reward' => $this->grantReadReward($child, $read),
            ];
        });
    }

    /**
     * @return array{xp: int, points: int, capped: bool}|null
     */
    private function grantReadReward(ChildProfile $child, EncyclopediaRead $read): ?array
    {
        $policy = RewardPolicy::query()->firstOrCreate(
            ['family_id' => $child->family_id, 'code' => self::POLICY_CODE],
            [
                'xp_amount' => 1,
                'coin_amount' => 0,
                'point_amount' => 1,
                'requires_approval' => false,
                'max_per_day' => self::REWARD_DAILY_LIMIT,
                'is_active' => true,
            ],
        );

        if (! $policy->is_active || $policy->requires_approval) {
            return null;
        }

        $grant = $this->rewards->grant(new RewardIntent(
            familyId: $child->family_id,
            childId: $child->id,
            policyCode: self::POLICY_CODE,
            grantKey: 'encyclopedia:read:'.$child->id.':'.$read->topic_id,
            sourceModule: SourceModule::Encyclopedia,
            sourceType: 'encyclopedia_read',
            sourceId: $read->id,
            occurredAt: $this->clock->now(),
            postImmediately: true,
        ));

        $xp = (int) $grant->xp_amount;
        $points = (int) $grant->point_amount;
        $capped = $xp === 0 && $points === 0;

        return [
            'xp' => $xp,
            'points' => $points,
            'capped' => $capped,
        ];
    }
}
