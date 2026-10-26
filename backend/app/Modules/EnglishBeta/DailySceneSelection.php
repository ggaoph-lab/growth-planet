<?php

namespace App\Modules\EnglishBeta;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailySceneSelection
{
    private const PAIRS = [
        ['supermarket', 'restaurant'],
        ['supermarket', 'bakery'],
        ['restaurant', 'toy_store'],
        ['bakery', 'toy_store'],
    ];

    public function offered(string $timezone, ?string $selected = null): array
    {
        $date = CarbonImmutable::now($timezone)->toDateString();
        $day = intdiv(CarbonImmutable::parse($date, 'UTC')->timestamp, 86400);
        $pair = self::PAIRS[($day % count(self::PAIRS) + count(self::PAIRS)) % count(self::PAIRS)];
        return $selected !== null && ! in_array($selected, $pair, true) ? [$selected, $pair[0]] : $pair;
    }

    public function selected(int $childId, string $timezone): ?string
    {
        $localStart = CarbonImmutable::now($timezone)->startOfDay();
        $start = $localStart->utc();
        $end = $localStart->addDay()->utc();
        return DB::table('english_beta_sessions')->where('child_id', $childId)
            ->where('started_at', '>=', $start)->where('started_at', '<', $end)
            ->orderBy('id')->value('scene');
    }
}
