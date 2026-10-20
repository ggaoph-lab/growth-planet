<?php

namespace App\Modules\Game\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $family_id
 * @property int $child_id
 * @property string $played_on
 * @property string $game
 * @property string $level
 * @property int $coins_awarded
 * @property int|null $reward_grant_id
 */
class GameDailyReward extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'played_on' => 'date',
            'coins_awarded' => 'integer',
        ];
    }
}
