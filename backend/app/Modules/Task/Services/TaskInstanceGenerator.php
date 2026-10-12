<?php

namespace App\Modules\Task\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class TaskInstanceGenerator
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return Collection<int, TaskInstance>
     */
    public function ensureForChildOnDate(Family $family, ChildProfile $child, string $date): Collection
    {
        $tasks = Task::query()
            ->with(['steps', 'schedules', 'parent'])
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->where(function ($q) use ($child) {
                $q->whereNull('child_id')->orWhere('child_id', $child->id);
            })
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->get();

        $instances = collect();
        foreach ($tasks as $task) {
            if (! $this->scheduleMatches($task, $family, $date)) {
                continue;
            }
            $instances->push($this->ensureInstance($task, $family, $child, $date));
        }

        return $instances;
    }

    private function scheduleMatches(Task $task, Family $family, string $date): bool
    {
        $schedules = $task->schedules;
        if ($schedules->isEmpty()) {
            return true;
        }

        foreach ($schedules as $schedule) {
            if ($schedule->frequency === ScheduleFrequency::Daily) {
                return true;
            }
            if ($schedule->frequency === ScheduleFrequency::Once) {
                return $task->start_date->toDateString() === $date;
            }
            if ($schedule->frequency === ScheduleFrequency::Dates) {
                $dates = $schedule->dates ?? [];

                return in_array($date, $dates, true);
            }
            if ($schedule->frequency === ScheduleFrequency::Weekly) {
                $weekday = (int) CarbonImmutable::createFromFormat('!Y-m-d', $date, $family->timezone)->dayOfWeekIso();
                $weekdays = $schedule->weekdays ?? [];

                return in_array($weekday, $weekdays, true);
            }
        }

        return false;
    }

    private function ensureInstance(Task $task, Family $family, ChildProfile $child, string $date): TaskInstance
    {
        $key = "task:{$task->id}:child:{$child->id}:{$date}";
        $existing = TaskInstance::query()->where('generation_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $snapshot = $task->instanceSnapshot();

        try {
            $instance = TaskInstance::query()->create([
                'family_id' => $family->id,
                'child_id' => $child->id,
                'task_id' => $task->id,
                'task_version' => $task->version,
                'task_date' => $date,
                'generation_key' => $key,
                'snapshot' => $snapshot,
                'status' => TaskInstanceStatus::Pending,
                'lock_version' => 0,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return TaskInstance::query()->where('generation_key', $key)->firstOrFail();
            }
            throw $e;
        }

        foreach ($snapshot['steps'] as $step) {
            TaskInstanceStep::query()->create([
                'task_instance_id' => $instance->id,
                'task_step_id' => $step['id'],
                'title' => $step['title'],
                'is_required' => $step['is_required'],
                'is_self_check' => $step['is_self_check'],
                'sort' => $step['sort'],
            ]);
        }

        return $instance->load('instanceSteps');
    }
}
