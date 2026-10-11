<?php

namespace App\Modules\ScreenTime\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\ScreenTime\Models\ScreenTimePolicy;

class ScreenTimePolicySeeder
{
    public function __construct(private readonly Clock $clock) {}

    public function seedForChild(ChildProfile $child): void
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $defaults = [
            ['category' => 'movie', 'period' => 'week', 'minutes' => 180],
            ['category' => 'english', 'period' => 'day', 'minutes' => 20],
        ];

        foreach ($defaults as $row) {
            ScreenTimePolicy::query()->firstOrCreate(
                [
                    'child_id' => $child->id,
                    'category' => $row['category'],
                    'period' => $row['period'],
                ],
                [
                    'family_id' => $child->family_id,
                    'minutes' => $row['minutes'],
                    'warn_before_minutes' => 15,
                    'carry_over' => false,
                    'effective_from' => $today,
                ],
            );
        }
    }
}
