<?php

namespace App\Modules\Game\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Game\Models\GameDailyReward;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Once-per-day points for finishing a game level: easy 1, practice 2, challenge 3.
 */
class GameLevelRewardService
{
    public const GAMES = ['mirror', 'sudoku'];

    public const LEVELS = ['easy', 'practice', 'challenge'];

    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
        private readonly GamePlayService $plays,
    ) {}

    public static function coins(string $level): int
    {
        return match ($level) {
            'easy' => 1,
            'practice' => 2,
            'challenge' => 3,
            default => throw new ApiException('VALIDATION_FAILED', BiMsg::of('难度不对', 'That level is not available')),
        };
    }

    public static function assertGame(string $game): void
    {
        if (! in_array($game, self::GAMES, true)) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('游戏不对', 'That game is not available'));
        }
    }

    /**
     * @return array{date: string, levels: list<array{id: string, coins: int, awarded: bool}>}
     */
    public function board(ChildProfile $child, Family $family, string $game): array
    {
        self::assertGame($game);
        $date = $this->clock->today($family->timezone)->toDateString();
        $rows = GameDailyReward::query()
            ->where('child_id', $child->id)
            ->where('played_on', $date)
            ->where('game', $game)
            ->get()
            ->keyBy('level');

        $levels = [];
        foreach (self::LEVELS as $level) {
            $row = $rows->get($level);
            $levels[] = [
                'id' => $level,
                'coins' => self::coins($level),
                'awarded' => ($row?->coins_awarded ?? 0) > 0,
                'remaining' => $this->plays->remaining($child, $family, $game, $level),
            ];
        }

        return ['date' => $date, 'levels' => $levels];
    }

    /**
     * @return array{awarded_coins: int, board: array{date: string, levels: list<array{id: string, coins: int, awarded: bool}>}}
     */
    public function claim(ChildProfile $child, Family $family, string $game, string $level, string $playId): array
    {
        self::assertGame($game);
        $coins = self::coins($level);
        $date = $this->clock->today($family->timezone)->toDateString();
        $awarded = 0;

        DB::transaction(function () use ($child, $family, $game, $level, $playId, $coins, $date, &$awarded): void {
            $this->plays->finish($child, $family, $game, $level, $playId);
            $row = $this->lockRow($child, $family, $game, $level, $date);
            if ($row->reward_grant_id !== null) {
                return;
            }

            $grant = $this->rewards->grant(new RewardIntent(
                familyId: $family->id,
                childId: $child->id,
                policyCode: 'game.'.$game,
                grantKey: $game.':'.$child->id.':'.$date.':'.$level,
                sourceModule: SourceModule::Game,
                sourceType: 'game_daily_reward',
                sourceId: $row->id,
                occurredAt: $this->clock->now(),
                xpAmount: 0,
                coinAmount: 0,
                pointAmount: $coins,
                postImmediately: true,
            ));
            $row->reward_grant_id = $grant->id;
            $awarded = $grant->status === RewardGrantStatus::Granted ? $grant->point_amount : 0;
            $row->coins_awarded = $awarded;
            $row->save();
        });

        return [
            'awarded_coins' => $awarded,
            'board' => $this->board($child, $family, $game),
        ];
    }

    private function lockRow(
        ChildProfile $child,
        Family $family,
        string $game,
        string $level,
        string $date,
    ): GameDailyReward {
        $row = GameDailyReward::query()
            ->where('child_id', $child->id)
            ->where('played_on', $date)
            ->where('game', $game)
            ->where('level', $level)
            ->lockForUpdate()
            ->first();

        if ($row !== null) {
            return $row;
        }

        try {
            return GameDailyReward::query()->create([
                'family_id' => $family->id,
                'child_id' => $child->id,
                'played_on' => $date,
                'game' => $game,
                'level' => $level,
                'coins_awarded' => 0,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Duplicate') && $e->getCode() !== '23000') {
                throw $e;
            }

            return GameDailyReward::query()
                ->where('child_id', $child->id)
                ->where('played_on', $date)
                ->where('game', $game)
                ->where('level', $level)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
