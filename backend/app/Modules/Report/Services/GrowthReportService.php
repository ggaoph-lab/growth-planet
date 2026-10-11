<?php

namespace App\Modules\Report\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Habit\Models\HabitRecord;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\ScreenTime\Models\ScreenTimeEntry;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;
use Illuminate\Support\Facades\DB;

class GrowthReportService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function daily(ChildProfile $child, string $date): array
    {
        $tasksDone = TaskInstance::query()
            ->where('child_id', $child->id)
            ->where('task_date', $date)
            ->where('status', TaskInstanceStatus::Completed)
            ->count();

        $habitsDone = HabitRecord::query()
            ->where('child_id', $child->id)
            ->where('record_date', $date)
            ->count();

        $englishMinutes = (int) EnglishWatchLog::query()
            ->where('child_id', $child->id)
            ->where('watch_date', $date)
            ->where('status', 'APPROVED')
            ->sum(DB::raw('COALESCE(approved_minutes, minutes)'));

        $screenSeconds = (int) ScreenTimeEntry::query()
            ->where('child_id', $child->id)
            ->where('period_start', $date)
            ->where('status', 'counted')
            ->sum('seconds');

        return [
            'date' => $date,
            'tasks_completed' => $tasksDone,
            'habits_logged' => $habitsDone,
            'english_minutes' => $englishMinutes,
            'screen_time_seconds' => $screenSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function weekly(ChildProfile $child, string $weekStart): array
    {
        $weekEnd = \Carbon\CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();

        $tasksDone = TaskInstance::query()
            ->where('child_id', $child->id)
            ->whereBetween('task_date', [$weekStart, $weekEnd])
            ->where('status', TaskInstanceStatus::Completed)
            ->count();

        $moviesCompleted = ChildMovieLibrary::query()
            ->where('child_id', $child->id)
            ->where('shelf', 'completed')
            ->where('updated_at', '>=', $weekStart)
            ->count();

        $englishMinutes = (int) EnglishWatchLog::query()
            ->where('child_id', $child->id)
            ->whereBetween('watch_date', [$weekStart, $weekEnd])
            ->where('status', 'APPROVED')
            ->sum(DB::raw('COALESCE(approved_minutes, minutes)'));

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'tasks_completed' => $tasksDone,
            'movies_completed' => $moviesCompleted,
            'english_minutes' => $englishMinutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function growthSummary(ChildProfile $child): array
    {
        $totalTasks = TaskInstance::query()
            ->where('child_id', $child->id)
            ->where('status', TaskInstanceStatus::Completed)
            ->count();

        $totalHabits = HabitRecord::query()->where('child_id', $child->id)->count();
        $totalMovies = ChildMovieLibrary::query()->where('child_id', $child->id)->where('ever_completed', true)->count();
        $totalEnglish = EnglishWatchLog::query()->where('child_id', $child->id)->where('status', 'APPROVED')->count();

        return [
            'tasks_completed_total' => $totalTasks,
            'habit_records_total' => $totalHabits,
            'movies_completed_total' => $totalMovies,
            'english_logs_approved_total' => $totalEnglish,
        ];
    }
}
