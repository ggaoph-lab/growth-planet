<?php

namespace App\Modules\Identity\Support;

use App\Modules\Core\Settings\Settings;
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
            self::planet('encyclopedia_planet', '科学小百科星球', 'Science encyclopedia', 'book', true, 7),
            self::planet('game_planet', '游戏星球', 'Game planet', 'gamepad', true, 7),
            self::planet('shop', '奖励商城', 'Reward shop', 'gift', true, 2),
            self::planet('collection', '成长收藏馆', 'Collection', 'museum', true, 6),
        ];
    }

    public static function enabled(string $key, int $familyId): bool
    {
        return (bool) app(Settings::class)->get('planets.'.$key, $familyId);
    }

    public static function forFamily(int $familyId): array
    {
        $all = self::all();
        // One settings round-trip — kid home used to pay ~12 sequential queries here.
        $flags = app(Settings::class)->getMany(
            array_map(fn (array $planet) => 'planets.'.$planet['key'], $all),
            $familyId,
        );

        return array_values(array_filter(
            $all,
            fn (array $planet) => (bool) ($flags['planets.'.$planet['key']] ?? true),
        ));
    }

    /** Shared by the child API guard; TTS stays available across modules. */
    public static function forEndpoint(string $segment): ?string
    {
        return match ($segment) {
            'tasks', 'task-instances', 'habits' => 'task_planet',
            'wallet' => 'wallet',
            'bag', 'items', 'item-checklists' => 'bag_planet',
            'schedule' => 'schedule_planet',
            'explorations' => 'explore_planet',
            'foods', 'food-records' => 'food_diary',
            'movies', 'movie-collections', 'movie-library', 'screen-time' => 'movie_planet',
            'english' => 'english_planet',
            'encyclopedia' => 'encyclopedia_planet',
            'games' => 'game_planet',
            'shop', 'wishes', 'redemptions' => 'shop',
            'collection' => 'collection',
            default => null,
        };
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
