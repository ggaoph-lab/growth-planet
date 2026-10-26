<?php

namespace App\Modules\Ielts\Services;

use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use Carbon\CarbonImmutable;

/**
 * Settles one Word Atlas (雅思星球) learning session into points and coins.
 *
 * The client sends the FSRS first-attempt grade per word (1 Again, 2 Hard, 3 Good, 4 Easy);
 * amounts are computed here so the client cannot pick its own reward:
 *   - points: 1 per Good/Easy word, 0.5 per Hard word, floored
 *   - coins: by first-try accuracy — >= 90% pays one coin per new word, 60–89% pays half, below 60% none
 *   - mastered: a fixed number of points the first time a word reaches FSRS "mastered"
 *
 * Daily caps and the mastered bonus are family settings (ielts.*), editable by parents.
 */
class IeltsRewardService
{
    public const SESSION_POLICY = 'ielts.session';

    public const MASTERED_POLICY = 'ielts.mastered';

    public const RATING_AGAIN = 1;

    public const RATING_HARD = 2;

    /** Coins paid per new word at the best accuracy tier. */
    public const COINS_PER_NEW_WORD = 1;

    /** Coins paid per session, before the daily cap. */
    public const COINS_PER_SESSION_CAP = 10;

    public const SETTING_KEYS = [
        'ielts.rewards_enabled',
        'ielts.points_daily_cap',
        'ielts.coins_daily_cap',
        'ielts.mastered_points',
        'ielts.mastered_daily_cap',
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly RewardGrantService $rewards,
    ) {}

    /**
     * Parent-facing view of the reward settings for one family.
     *
     * @return array{rewards_enabled: bool, points_daily_cap: int, coins_daily_cap: int, mastered_points: int, mastered_daily_cap: int}
     */
    public function options(int $familyId): array
    {
        $raw = $this->settings->getMany(self::SETTING_KEYS, $familyId);

        return [
            'rewards_enabled' => (bool) $raw['ielts.rewards_enabled'],
            'points_daily_cap' => (int) $raw['ielts.points_daily_cap'],
            'coins_daily_cap' => (int) $raw['ielts.coins_daily_cap'],
            'mastered_points' => (int) $raw['ielts.mastered_points'],
            'mastered_daily_cap' => (int) $raw['ielts.mastered_daily_cap'],
        ];
    }

    /**
     * @param  array<string, int>  $graded  word id => first-attempt grade (1..4)
     * @param  list<string>  $newIds  words met for the first time in this session
     * @param  list<string>  $masteredIds  words that reached "mastered" during this session
     * @return array{
     *     enabled: bool,
     *     settled: bool,
     *     points: int,
     *     coins: int,
     *     accuracy: int,
     *     mastered_points: int,
     *     mastered_words: int,
     *     capped: bool,
     *     remaining: array{points: int, coins: int}
     * }
     */
    public function settle(
        ChildProfile $child,
        Family $family,
        string $sessionId,
        array $graded,
        array $newIds,
        array $masteredIds,
    ): array {
        $options = $this->options($family->id);
        $sessionPolicy = $this->ensurePolicy($family->id, self::SESSION_POLICY);
        $masteredPolicy = $this->ensurePolicy($family->id, self::MASTERED_POLICY);

        $computed = self::compute($graded, $newIds);
        $grantKey = 'ielts:session:'.$child->id.':'.$sessionId;
        [$dayStart, $dayEnd] = $this->todayRange($family);

        $result = [
            'enabled' => $options['rewards_enabled'],
            'settled' => false,
            'points' => 0,
            'coins' => 0,
            'accuracy' => $computed['accuracy'],
            'mastered_points' => 0,
            'mastered_words' => 0,
            'capped' => false,
            'remaining' => ['points' => 0, 'coins' => 0],
        ];

        if (! $options['rewards_enabled']) {
            return $result;
        }

        $sessionPaid = $this->paidToday($child, self::SESSION_POLICY, $dayStart, $dayEnd);
        $pointsRemaining = max(0, $options['points_daily_cap'] - $sessionPaid['points']);
        $coinsRemaining = max(0, $options['coins_daily_cap'] - $sessionPaid['coins']);

        if ($sessionPolicy->is_active && ! $sessionPolicy->requires_approval && $computed['total'] > 0) {
            $existing = RewardGrant::query()->where('grant_key', $grantKey)->first();
            if ($existing === null) {
                $points = min($computed['points'], $pointsRemaining);
                $coins = min($computed['coins'], $coinsRemaining);
                $grant = $this->rewards->grant(new RewardIntent(
                    familyId: $family->id,
                    childId: $child->id,
                    policyCode: self::SESSION_POLICY,
                    grantKey: $grantKey,
                    sourceModule: SourceModule::Ielts,
                    sourceType: 'ielts_session',
                    sourceId: null,
                    occurredAt: $this->clock->now(),
                    xpAmount: 0,
                    coinAmount: $coins,
                    pointAmount: $points,
                    postImmediately: true,
                ));
                $paid = in_array($grant->status, [RewardGrantStatus::Granted, RewardGrantStatus::Capped], true);
                $result['settled'] = true;
                $result['points'] = $paid ? (int) $grant->point_amount : 0;
                $result['coins'] = $paid ? (int) $grant->coin_amount : 0;
                $result['capped'] = $result['points'] < $computed['points'] || $result['coins'] < $computed['coins'];
                $pointsRemaining -= $result['points'];
                $coinsRemaining -= $result['coins'];
            }
        }

        if ($masteredPolicy->is_active && ! $masteredPolicy->requires_approval && $masteredIds !== [] && $options['mastered_points'] > 0) {
            $masteredRemaining = max(0, $options['mastered_daily_cap'] - $this->paidToday($child, self::MASTERED_POLICY, $dayStart, $dayEnd)['points']);
            $keys = array_map(fn (string $id) => 'ielts:mastered:'.$child->id.':'.$id, array_values(array_unique($masteredIds)));
            $alreadyPaid = RewardGrant::query()->whereIn('grant_key', $keys)->pluck('grant_key')->flip();
            foreach ($keys as $key) {
                if ($alreadyPaid->has($key)) {
                    continue; // paid on an earlier settle
                }
                $amount = min($options['mastered_points'], $masteredRemaining);
                $grant = $this->rewards->grant(new RewardIntent(
                    familyId: $family->id,
                    childId: $child->id,
                    policyCode: self::MASTERED_POLICY,
                    grantKey: $key,
                    sourceModule: SourceModule::Ielts,
                    sourceType: 'ielts_word',
                    sourceId: null,
                    occurredAt: $this->clock->now(),
                    xpAmount: 0,
                    coinAmount: 0,
                    pointAmount: $amount,
                    postImmediately: true,
                ));
                $granted = (int) $grant->point_amount;
                $result['mastered_words']++;
                $result['mastered_points'] += $granted;
                $masteredRemaining -= $granted;
                if ($granted < $options['mastered_points']) {
                    $result['capped'] = true;
                }
            }
        }

        $result['remaining'] = ['points' => max(0, $pointsRemaining), 'coins' => max(0, $coinsRemaining)];

        return $result;
    }

    /**
     * Pure reward maths, shared with tests and documentation.
     *
     * @param  array<string, int>  $graded
     * @param  list<string>  $newIds
     * @return array{total: int, correct: int, accuracy: int, new_words: int, points: int, coins: int}
     */
    public static function compute(array $graded, array $newIds): array
    {
        $total = count($graded);
        $correct = 0;
        $halfPoints = 0;
        foreach ($graded as $grade) {
            if ($grade === self::RATING_AGAIN) {
                continue;
            }
            $correct++;
            $halfPoints += $grade === self::RATING_HARD ? 1 : 2;
        }
        $points = intdiv($halfPoints, 2);
        $accuracy = $total > 0 ? (int) round($correct / $total * 100) : 0;

        $newWords = count(array_intersect($newIds, array_keys($graded)));
        $coins = 0;
        if ($total > 0) {
            if ($accuracy >= 90) {
                $coins = $newWords * self::COINS_PER_NEW_WORD;
            } elseif ($accuracy >= 60) {
                $coins = intdiv($newWords * self::COINS_PER_NEW_WORD, 2);
            }
        }
        $coins = min($coins, self::COINS_PER_SESSION_CAP);

        return [
            'total' => $total,
            'correct' => $correct,
            'accuracy' => $accuracy,
            'new_words' => $newWords,
            'points' => $points,
            'coins' => $coins,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function todayRange(Family $family): array
    {
        $today = $this->clock->today($family->timezone);

        return [$today->utc(), $today->addDay()->utc()];
    }

    /** @return array{points: int, coins: int} */
    private function paidToday(ChildProfile $child, string $policyCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $row = RewardGrant::query()
            ->where('child_id', $child->id)
            ->where('policy_code', $policyCode)
            ->whereIn('status', [RewardGrantStatus::Granted, RewardGrantStatus::Capped])
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->selectRaw('COALESCE(SUM(point_amount), 0) AS points, COALESCE(SUM(coin_amount), 0) AS coins')
            ->first();

        return ['points' => (int) ($row->points ?? 0), 'coins' => (int) ($row->coins ?? 0)];
    }

    /**
     * Families created before this planet shipped have no policy row yet. Amounts and caps live in
     * family settings, so the row only carries the on/off and approval switches.
     */
    private function ensurePolicy(int $familyId, string $code): RewardPolicy
    {
        return RewardPolicy::query()->firstOrCreate(
            ['family_id' => $familyId, 'code' => $code],
            [
                'xp_amount' => 0,
                'coin_amount' => 0,
                'point_amount' => 0,
                'requires_approval' => false,
                'max_per_day' => null,
                'is_active' => true,
            ],
        );
    }
}
