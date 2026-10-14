<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Schedule\DTO\ResolvedDaySchedule;
use App\Modules\Schedule\Enums\ScheduleExceptionType;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\SchoolPeriod;
use App\Modules\Schedule\Models\SchoolSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ScheduleResolver
{
    public function resolve(ChildProfile $child, Family $family, string $date): ResolvedDaySchedule
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $family->timezone);
        $exception = $this->findException($family->id, $child->id, $date);

        if ($exception?->type === ScheduleExceptionType::NoSchool) {
            return new ResolvedDaySchedule($date, false, [], [], $exception);
        }

        if ($exception?->type === ScheduleExceptionType::Custom) {
            $periods = $this->normalizeCustomPeriods($exception->custom_periods ?? []);

            return new ResolvedDaySchedule(
                $date,
                $periods !== [],
                $periods,
                $this->uniqueSubjects($periods),
                $exception,
            );
        }

        $weekday = $exception?->type === ScheduleExceptionType::MakeupDay
            ? (int) $exception->follow_weekday
            : (int) $day->isoWeekday();

        $schedule = $this->activeSchedule($child->id, $date);

        // No weekly timetable configured yet → keep bag checklists working (daily items only).
        if ($schedule === null) {
            return new ResolvedDaySchedule($date, true, [], [], $exception);
        }

        if ($weekday < 1 || $weekday > 7) {
            return new ResolvedDaySchedule($date, false, [], [], $exception, $schedule->id);
        }

        $periods = $this->periodsForWeekday($schedule, $weekday);

        return new ResolvedDaySchedule(
            $date,
            $periods !== [],
            $periods,
            $this->uniqueSubjects($periods),
            $exception,
            $schedule->id,
        );
    }

    public function activeSchedule(int $childId, string $date): ?SchoolSchedule
    {
        return SchoolSchedule::query()
            ->where('child_id', $childId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return list<array{period_index: int, subject: string, start_time: ?string, end_time: ?string, room: ?string}>
     */
    public function periodsForWeekday(SchoolSchedule $schedule, int $weekday): array
    {
        /** @var Collection<int, SchoolPeriod> $rows */
        $rows = $schedule->periods()
            ->where('weekday', $weekday)
            ->orderBy('period_index')
            ->get();

        return $rows->map(fn (SchoolPeriod $p) => $this->periodArray($p))->values()->all();
    }

    /**
     * @return array{period_index: int, subject: string, start_time: ?string, end_time: ?string, room: ?string}
     */
    private function periodArray(SchoolPeriod $period): array
    {
        return [
            'period_index' => (int) $period->period_index,
            'subject' => (string) $period->subject,
            'start_time' => $period->start_time !== null ? substr((string) $period->start_time, 0, 5) : null,
            'end_time' => $period->end_time !== null ? substr((string) $period->end_time, 0, 5) : null,
            'room' => $period->room,
        ];
    }

    private function findException(int $familyId, int $childId, string $date): ?ScheduleException
    {
        $childSpecific = ScheduleException::query()
            ->where('family_id', $familyId)
            ->where('child_id', $childId)
            ->whereDate('exception_date', $date)
            ->first();

        if ($childSpecific !== null) {
            return $childSpecific;
        }

        return ScheduleException::query()
            ->where('family_id', $familyId)
            ->whereNull('child_id')
            ->whereDate('exception_date', $date)
            ->first();
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{period_index: int, subject: string, start_time: ?string, end_time: ?string, room: ?string}>
     */
    private function normalizeCustomPeriods(array $raw): array
    {
        $out = [];
        foreach ($raw as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $subject = trim((string) ($row['subject'] ?? ''));
            if ($subject === '') {
                continue;
            }
            $out[] = [
                'period_index' => (int) ($row['period_index'] ?? ($i + 1)),
                'subject' => $subject,
                'start_time' => isset($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : null,
                'end_time' => isset($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : null,
                'room' => isset($row['room']) ? (string) $row['room'] : null,
            ];
        }
        usort($out, fn ($a, $b) => $a['period_index'] <=> $b['period_index']);

        return $out;
    }

    /**
     * @param  list<array{subject: string}>  $periods
     * @return list<string>
     */
    private function uniqueSubjects(array $periods): array
    {
        $seen = [];
        foreach ($periods as $p) {
            $s = $p['subject'];
            if ($s !== '' && ! in_array($s, $seen, true)) {
                $seen[] = $s;
            }
        }

        return $seen;
    }
}
