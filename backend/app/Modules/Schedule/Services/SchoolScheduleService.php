<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Schedule\Models\SchoolPeriod;
use App\Modules\Schedule\Models\SchoolSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolScheduleService
{
    /**
     * Replace (or create) the child's active weekly schedule for the given effective range.
     *
     * @param  list<array{weekday: int, period_index: int, subject: string, start_time?: ?string, end_time?: ?string, room?: ?string}>  $periods
     */
    public function putWeekly(
        ChildProfile $child,
        string $title,
        int $periodCount,
        string $effectiveFrom,
        ?string $effectiveTo,
        array $periods,
    ): SchoolSchedule {
        if ($periodCount < 1 || $periodCount > 12) {
            throw ValidationException::withMessages(['period_count' => ['节次数需在 1–12']]);
        }

        return DB::transaction(function () use ($child, $title, $periodCount, $effectiveFrom, $effectiveTo, $periods) {
            // Close overlapping previous schedules ending just before the new one starts.
            SchoolSchedule::query()
                ->where('child_id', $child->id)
                ->where(function ($q) use ($effectiveFrom) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom);
                })
                ->where('effective_from', '<', $effectiveFrom)
                ->update([
                    'effective_to' => CarbonImmutable::createFromFormat('!Y-m-d', $effectiveFrom)->subDay()->toDateString(),
                ]);

            // Soft-delete any schedule that starts on/after this effective_from (full replace).
            SchoolSchedule::query()
                ->where('child_id', $child->id)
                ->where('effective_from', '>=', $effectiveFrom)
                ->get()
                ->each(fn (SchoolSchedule $s) => $s->delete());

            $schedule = SchoolSchedule::query()->create([
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'title' => $title,
                'period_count' => $periodCount,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);

            foreach ($periods as $row) {
                $subject = trim((string) ($row['subject'] ?? ''));
                if ($subject === '') {
                    continue;
                }
                $weekday = (int) $row['weekday'];
                $periodIndex = (int) $row['period_index'];
                if ($weekday < 1 || $weekday > 7 || $periodIndex < 1 || $periodIndex > $periodCount) {
                    throw ValidationException::withMessages([
                        'periods' => ['节次 weekday/period_index 无效'],
                    ]);
                }
                SchoolPeriod::query()->create([
                    'school_schedule_id' => $schedule->id,
                    'weekday' => $weekday,
                    'period_index' => $periodIndex,
                    'subject' => $subject,
                    'start_time' => $row['start_time'] ?? null,
                    'end_time' => $row['end_time'] ?? null,
                    'room' => $row['room'] ?? null,
                ]);
            }

            return $schedule->load('periods');
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serialize(?SchoolSchedule $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        $periods = $schedule->periods
            ->sortBy([['weekday', 'asc'], ['period_index', 'asc']])
            ->values()
            ->map(fn (SchoolPeriod $p) => [
                'id' => $p->id,
                'weekday' => (int) $p->weekday,
                'period_index' => (int) $p->period_index,
                'subject' => $p->subject,
                'start_time' => $p->start_time !== null ? substr((string) $p->start_time, 0, 5) : null,
                'end_time' => $p->end_time !== null ? substr((string) $p->end_time, 0, 5) : null,
                'room' => $p->room,
            ])
            ->all();

        return [
            'id' => $schedule->id,
            'child_id' => $schedule->child_id,
            'title' => $schedule->title,
            'period_count' => (int) $schedule->period_count,
            'effective_from' => $schedule->effective_from?->toDateString(),
            'effective_to' => $schedule->effective_to?->toDateString(),
            'periods' => $periods,
        ];
    }
}
