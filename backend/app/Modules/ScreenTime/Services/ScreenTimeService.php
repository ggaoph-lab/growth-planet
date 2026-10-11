<?php

namespace App\Modules\ScreenTime\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\ScreenTime\Models\ScreenTimeAdjustment;
use App\Modules\ScreenTime\Models\ScreenTimeEntry;
use App\Modules\ScreenTime\Models\ScreenTimePolicy;
use Illuminate\Database\QueryException;

class ScreenTimeService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Idempotent screen time credit for a business source (one row per source).
     */
    public function recordCounted(
        ChildProfile $child,
        string $category,
        string $sourceType,
        int $sourceId,
        int $seconds,
        string $periodStart,
        string $entryMethod = 'auto',
    ): ?ScreenTimeEntry {
        if ($seconds <= 0) {
            return null;
        }

        try {
            return ScreenTimeEntry::query()->create([
                'child_id' => $child->id,
                'category' => $category,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'period_start' => $periodStart,
                'seconds' => $seconds,
                'entry_method' => $entryMethod,
                'status' => 'counted',
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return ScreenTimeEntry::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->first();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForChild(ChildProfile $child): array
    {
        $tz = $child->family->timezone;
        $today = $this->clock->today($tz)->toDateString();
        [$weekStartUtc] = $this->clock->weekRange($tz);
        $weekStart = $weekStartUtc->setTimezone($tz)->toDateString();

        $policies = ScreenTimePolicy::query()->where('child_id', $child->id)->get()->keyBy(fn ($p) => $p->category.':'.$p->period);

        $movieUsed = (int) ScreenTimeEntry::query()
            ->where('child_id', $child->id)
            ->where('category', 'movie')
            ->where('period_start', $weekStart)
            ->where('status', 'counted')
            ->sum('seconds');

        $englishUsed = (int) ScreenTimeEntry::query()
            ->where('child_id', $child->id)
            ->where('category', 'english')
            ->where('period_start', $today)
            ->where('status', 'counted')
            ->sum('seconds');

        $movieAdj = (int) ScreenTimeAdjustment::query()
            ->where('child_id', $child->id)
            ->where('category', 'movie')
            ->where('period_start', $weekStart)
            ->sum('seconds');

        $englishAdj = (int) ScreenTimeAdjustment::query()
            ->where('child_id', $child->id)
            ->where('category', 'english')
            ->where('period_start', $today)
            ->sum('seconds');

        $movieLimit = (int) ($policies->get('movie:week')?->minutes ?? 180) * 60;
        $englishLimit = (int) ($policies->get('english:day')?->minutes ?? 20) * 60;

        return [
            'movie' => [
                'period' => 'week',
                'period_start' => $weekStart,
                'limit_seconds' => $movieLimit,
                'used_seconds' => $movieUsed + $movieAdj,
                'remaining_seconds' => max(0, $movieLimit - $movieUsed - $movieAdj),
            ],
            'english' => [
                'period' => 'day',
                'period_start' => $today,
                'limit_seconds' => $englishLimit,
                'used_seconds' => $englishUsed + $englishAdj,
                'remaining_seconds' => max(0, $englishLimit - $englishUsed - $englishAdj),
            ],
        ];
    }
}
