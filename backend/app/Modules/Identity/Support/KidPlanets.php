<?php

namespace App\Modules\Identity\Support;

use App\Modules\Core\Support\BiMsg;

/**
 * Entries on the child home screen. A planet is only marked available once its module
 * is implemented end-to-end; until then the child sees "即将开放", never placeholder data.
 */
class KidPlanets
{
    /**
     * @return list<array{key: string, title: string, icon: string, available: bool, opens_in_phase: int}>
     */
    public static function all(): array
    {
        return [
            self::planet('task_planet', '任务星球', 'Task planet', 'planet-task', true, 2),
            self::planet('wallet', '我的积分', 'My points', 'coin', true, 2),
            self::planet('bag_planet', '书包星球', 'Bag planet', 'backpack', true, 3),
            self::planet('schedule_planet', '课程表星球', 'Timetable planet', 'calendar', true, 3),
            self::planet('explore_planet', '勇敢探索星球', 'Brave explore', 'compass', true, 3),
            self::planet('food_diary', '食物探索日记', 'Food diary', 'food', true, 3),
            self::planet('movie_planet', '电影星球', 'Movie planet', 'movie', true, 4),
            self::planet('english_planet', '英语探索星球', 'English explore', 'abc', true, 5),
            self::planet('game_planet', '游戏星球', 'Game planet', 'gamepad', true, 7),
            self::planet('shop', '奖励商城', 'Reward shop', 'gift', true, 2),
            self::planet('collection', '成长收藏馆', 'Collection', 'museum', true, 6),
        ];
    }

    /**
     * @return array{key: string, title: string, icon: string, available: bool, opens_in_phase: int}
     */
    private static function planet(string $key, string $zh, string $en, string $icon, bool $available, int $phase): array
    {
        return [
            'key' => $key,
            'title' => BiMsg::of($zh, $en),
            'icon' => $icon,
            'available' => $available,
            'opens_in_phase' => $phase,
        ];
    }
}
