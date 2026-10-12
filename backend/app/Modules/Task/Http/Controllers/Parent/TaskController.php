<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Enums\TaskKind;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use App\Modules\Task\Models\TaskSchedule;
use App\Modules\Task\Models\TaskStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $tasks = Task::query()
            ->with(['steps', 'schedules'])
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTask($request);
        $task = DB::transaction(function () use ($data) {
            $task = Task::query()->create([
                'family_id' => $this->context->familyId(),
                'child_id' => $data['child_id'] ?? null,
                'category' => $data['category'] ?? null,
                'title' => $data['title'],
                'icon' => $data['icon'] ?? null,
                'description' => $data['description'] ?? null,
                'kind' => $data['kind'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'xp_reward' => $data['xp_reward'] ?? 0,
                'coin_reward' => $data['coin_reward'] ?? 0,
                'requires_approval' => $data['requires_approval'] ?? false,
                'requires_self_check' => $data['requires_self_check'] ?? false,
                'with_parent' => $data['with_parent'] ?? false,
                'is_active' => true,
                'version' => 1,
                'created_by' => $this->context->user()->id,
            ]);

            $this->syncSteps($task, $data['steps'] ?? []);
            $this->syncSchedules($task, $data['schedules'] ?? [['frequency' => 'daily']]);

            return $task->load(['steps', 'schedules']);
        });

        return response()->json(['data' => $task], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $task = Task::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $this->validateTask($request, partial: true);

        $task = DB::transaction(function () use ($task, $data) {
            $task->fill(collect($data)->except(['steps', 'schedules'])->all());
            $task->version++;
            $task->save();

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($task, $data['steps'] ?? []);
            }
            if (array_key_exists('schedules', $data)) {
                $this->syncSchedules($task, $data['schedules'] ?? []);
            }

            $task->load(['steps', 'schedules']);
            $this->refreshPendingInstances($task);

            return $task;
        });

        return response()->json(['data' => $task]);
    }

    public function destroy(int $id): JsonResponse
    {
        $task = Task::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $task->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTask(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'child_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:32'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'icon' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'kind' => [$partial ? 'sometimes' : 'required', Rule::enum(TaskKind::class)],
            'start_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'end_date' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'xp_reward' => ['nullable', 'integer', 'min:0'],
            'coin_reward' => ['nullable', 'integer', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
            'requires_self_check' => ['nullable', 'boolean'],
            'with_parent' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array'],
            'steps.*.title' => ['required_with:steps', 'string', 'max:128'],
            'steps.*.is_required' => ['nullable', 'boolean'],
            'steps.*.is_self_check' => ['nullable', 'boolean'],
            'steps.*.sort' => ['nullable', 'integer', 'min:0'],
            'schedules' => ['nullable', 'array'],
            'schedules.*.frequency' => ['required_with:schedules', Rule::enum(ScheduleFrequency::class)],
            'schedules.*.weekdays' => ['nullable', 'array'],
            'schedules.*.weekdays.*' => ['integer', 'min:1', 'max:7'],
            'schedules.*.dates' => ['nullable', 'array'],
            'schedules.*.dates.*' => ['date'],
            'schedules.*.due_time' => ['nullable', 'date_format:H:i'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function syncSteps(Task $task, array $steps): void
    {
        TaskStep::query()->where('task_id', $task->id)->delete();
        foreach (array_values($steps) as $i => $step) {
            TaskStep::query()->create([
                'task_id' => $task->id,
                'title' => $step['title'],
                'is_required' => $step['is_required'] ?? true,
                'is_self_check' => $step['is_self_check'] ?? false,
                'sort' => $step['sort'] ?? $i,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     */
    private function syncSchedules(Task $task, array $schedules): void
    {
        TaskSchedule::query()->where('task_id', $task->id)->delete();
        foreach ($schedules as $schedule) {
            $dueTime = $schedule['due_time'] ?? null;
            if (is_string($dueTime) && strlen($dueTime) === 5) {
                $dueTime .= ':00';
            }
            TaskSchedule::query()->create([
                'task_id' => $task->id,
                'frequency' => $schedule['frequency'],
                'weekdays' => $schedule['weekdays'] ?? null,
                'dates' => $schedule['dates'] ?? null,
                'due_time' => $dueTime,
            ]);
        }
    }

    private function refreshPendingInstances(Task $task): void
    {
        $dueTime = $task->schedules->first()?->due_time;
        $snapshot = [
            'title' => $task->title,
            'icon' => $task->icon,
            'xp_reward' => $task->xp_reward,
            'coin_reward' => $task->coin_reward,
            'requires_approval' => $task->requires_approval,
            'requires_self_check' => $task->requires_self_check,
            'with_parent' => $task->with_parent,
            'due_time' => $dueTime,
            'steps' => $task->steps->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'is_required' => $s->is_required,
                'is_self_check' => $s->is_self_check,
                'sort' => $s->sort,
            ])->values()->all(),
        ];

        $instances = TaskInstance::query()
            ->where('task_id', $task->id)
            ->where('status', TaskInstanceStatus::Pending)
            ->get();

        foreach ($instances as $instance) {
            $instance->snapshot = $snapshot;
            $instance->task_version = $task->version;
            $instance->save();

            TaskInstanceStep::query()->where('task_instance_id', $instance->id)->delete();
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
        }
    }
}
