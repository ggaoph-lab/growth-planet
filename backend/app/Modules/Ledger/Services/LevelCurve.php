<?php

namespace App\Modules\Ledger\Services;

/**
 * Habitica-style XP-to-next-level curve.
 *
 * @see https://github.com/HabitRPG/habitica/blob/develop/website/common/script/statHelpers.js
 */
class LevelCurve
{
    public const DEFAULT_MAX_LEVEL = 300;

    public function toNextLevel(int $level): int
    {
        if ($level < 1) {
            $level = 1;
        }
        if ($level < 5) {
            return 25 * $level;
        }
        if ($level === 5) {
            return 150;
        }

        return (int) (round(((0.25 * ($level ** 2)) + (10 * $level) + 139.75) / 10) * 10);
    }
}
