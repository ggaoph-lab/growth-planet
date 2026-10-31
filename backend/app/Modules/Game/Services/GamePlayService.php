<?php

namespace App\Modules\Game\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GamePlayService
{
    public const LIMIT = 2;

    public function __construct(private readonly Clock $clock) {}

    public function date(Family $family): string
    {
        return $this->clock->today($family->timezone)->toDateString();
    }

    public function remaining(ChildProfile $child, Family $family, string $game, string $level): int
    {
        return max(0, self::LIMIT - DB::table('game_play_sessions')
            ->where('child_id', $child->id)->where('played_on', $this->date($family))
            ->where('game', $game)->where('level', $level)->count());
    }

    public function start(ChildProfile $child, Family $family, string $game, string $level): array
    {
        $this->assertCombination($game, $level);
        return DB::transaction(function () use ($child, $family, $game, $level): array {
            // A child row serializes starts across devices, including the first start of a day.
            ChildProfile::query()->whereKey($child->id)->lockForUpdate()->firstOrFail();
            $date = $this->date($family);
            $used = DB::table('game_play_sessions')->where('child_id', $child->id)
                ->where('played_on', $date)->where('game', $game)->where('level', $level)->count();
            if ($used >= self::LIMIT) {
                throw new ApiException('GAME_DAILY_LIMIT', BiMsg::of('今天已经玩过两次了，明天再玩吧', 'Two plays today already. Come back tomorrow.'), 429);
            }
            $id = (string) Str::uuid();
            DB::table('game_play_sessions')->insert([
                'id' => $id, 'family_id' => $family->id, 'child_id' => $child->id,
                'played_on' => $date, 'game' => $game, 'level' => $level,
                'slot' => $used + 1, 'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);
            return ['play_id' => $id, 'remaining' => self::LIMIT - $used - 1, 'date' => $date];
        });
    }

    public function finish(ChildProfile $child, Family $family, string $game, string $level, string $playId): void
    {
        $updated = DB::table('game_play_sessions')->where('id', $playId)
            ->where('child_id', $child->id)->where('family_id', $family->id)
            ->where('game', $game)->where('level', $level)
            ->where('played_on', $this->date($family))->whereNull('finished_at')
            ->update(['finished_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
        if ($updated !== 1) {
            throw new ApiException('GAME_PLAY_INVALID', BiMsg::of('这局游戏无法保存', 'This play cannot be saved'));
        }
    }

    private function assertCombination(string $game, string $level): void
    {
        $valid = $game === 'schulte' ? in_array($level, ['3', '4', '5'], true)
            : in_array($game, GameLevelRewardService::GAMES, true)
                && in_array($level, GameLevelRewardService::LEVELS, true);
        if (! $valid) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('游戏或难度不对', 'That game or level is not available'));
        }
    }
}
