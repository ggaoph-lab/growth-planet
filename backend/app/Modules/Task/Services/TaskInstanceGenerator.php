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
                $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date, $family->timezone)->isoWeekday();
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
            return $this->hydrateExisting($existing, $task);
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
                return $this->hydrateExisting(TaskInstance::query()->where('generation_key', $key)->firstOrFail(), $task);
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

    /**
     * Older instances were saved before group metadata or steps existed.
     * Fill those in so the kid card can list each item.
     */
    private function hydrateExisting(TaskInstance $instance, Task $task): TaskInstance
    {
        $task->loadMissing(['steps', 'parent']);
        $snapshot = $instance->snapshot ?? [];
        $changed = false;

        // Older homework imports merged "title：detail" into title only — split once for kid display/TTS.
        if (
            $task->parent_id !== null
            && ($task->description === null || trim((string) $task->description) === '')
            && is_string($task->title)
            && preg_match('/^(.+?)[：:](.+)$/u', $task->title, $parts) === 1
        ) {
            $task->title = mb_substr(trim($parts[1]), 0, 128);
            $task->description = mb_substr(trim($parts[2]), 0, 2000);
            $task->save();
        }

        $groupId = $task->parent_id !== null ? (int) $task->parent_id : null;
        $groupTitle = $task->parent?->title;
        if (($snapshot['group_task_id'] ?? null) != $groupId || ($snapshot['group_title'] ?? null) !== $groupTitle) {
            $snapshot['group_task_id'] = $groupId;
            $snapshot['group_title'] = $groupTitle;
            $changed = true;
        }

        $description = $task->description;
        if (($snapshot['description'] ?? null) !== $description) {
            $snapshot['description'] = $description;
            $changed = true;
        }
        if (($snapshot['title'] ?? null) !== $task->title) {
            $snapshot['title'] = $task->title;
            $changed = true;
        }

        $steps = $snapshot['steps'] ?? null;
        if ($task->steps->isNotEmpty() && (! is_array($steps) || $steps === [])) {
            $snapshot['steps'] = $task->instanceSnapshot()['steps'];
            $changed = true;
        }

        if ($changed) {
            $instance->snapshot = $snapshot;
            $instance->save();
        }

        if (! $instance->instanceSteps()->exists() && is_array($snapshot['steps'] ?? null)) {
            foreach ($snapshot['steps'] as $step) {
                if (! is_array($step) || ! isset($step['title'])) {
                    continue;
                }
                TaskInstanceStep::query()->create([
                    'task_instance_id' => $instance->id,
                    'task_step_id' => $step['id'] ?? null,
                    'title' => $step['title'],
                    'is_required' => $step['is_required'] ?? true,
                    'is_self_check' => $step['is_self_check'] ?? false,
                    'sort' => $step['sort'] ?? 0,
                ]);
            }
        }

        return $instance->load('instanceSteps');
    }
}
