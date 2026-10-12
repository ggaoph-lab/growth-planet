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
            ->with(['steps', 'schedules', 'taskCategory', 'children.schedules', 'children.steps'])
            ->where('family_id', $this->context->familyId())
            ->whereNull('parent_id')
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
                'category_id' => $data['category_id'] ?? null,
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
            $this->syncSubtasks($task, $data['subtasks'] ?? []);

            return $this->present($task);
        });

        return response()->json(['data' => $task], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $task = Task::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $this->validateTask($request, partial: true);

        $task = DB::transaction(function () use ($task, $data) {
            $task->fill(collect($data)->except(['steps', 'schedules', 'subtasks'])->all());
            $task->version++;
            $task->save();

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($task, $data['steps'] ?? []);
            }
            if (array_key_exists('schedules', $data)) {
                $this->syncSchedules($task, $data['schedules'] ?? []);
            }
            if (array_key_exists('subtasks', $data) && $task->parent_id === null) {
                $this->syncSubtasks($task, $data['subtasks'] ?? []);
            } elseif ($task->parent_id === null) {
                $this->propagateToChildren($task);
            }

            if ($task->children()->exists()) {
                TaskInstance::query()
                    ->where('task_id', $task->id)
                    ->where('status', TaskInstanceStatus::Pending)
                    ->delete();
            } else {
                $this->refreshPendingInstances($task);
            }

            return $this->present($task);
        });

        return response()->json(['data' => $task]);
    }

    public function destroy(int $id): JsonResponse
    {
        $task = Task::query()
            ->where('family_id', $this->context->familyId())
            ->whereNull('parent_id')
            ->findOrFail($id);

        DB::transaction(function () use ($task) {
            Task::query()->where('parent_id', $task->id)->delete();
            $task->delete();
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTask(Request $request, bool $partial = false): array
    {
        $familyId = $this->context->familyId();

        return $request->validate([
            'child_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:32'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('task_categories', 'id')->where('family_id', $familyId),
            ],
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
            'subtasks' => ['nullable', 'array'],
            'subtasks.*.id' => ['nullable', 'integer'],
            'subtasks.*.title' => ['required', 'string', 'max:128'],
            'subtasks.*.start_date' => ['nullable', 'date'],
            'subtasks.*.end_date' => ['nullable', 'date'],
            'subtasks.*.xp_reward' => ['nullable', 'integer', 'min:0'],
            'subtasks.*.coin_reward' => ['nullable', 'integer', 'min:0'],
            'subtasks.*.with_parent' => ['nullable', 'boolean'],
            'subtasks.*.due_time' => ['nullable', 'date_format:H:i'],
            'subtasks.*.schedules' => ['nullable', 'array'],
            'subtasks.*.schedules.*.frequency' => ['required', Rule::enum(ScheduleFrequency::class)],
            'subtasks.*.schedules.*.weekdays' => ['nullable', 'array'],
            'subtasks.*.schedules.*.weekdays.*' => ['integer', 'min:1', 'max:7'],
            'subtasks.*.schedules.*.dates' => ['nullable', 'array'],
            'subtasks.*.schedules.*.dates.*' => ['date'],
            'subtasks.*.schedules.*.due_time' => ['nullable', 'date_format:H:i'],
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
        $task->unsetRelation('schedules');
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function syncSubtasks(Task $parent, array $subtasks): void
    {
        $keptIds = [];
        foreach (array_values($subtasks) as $sub) {
            $child = null;
            if (! empty($sub['id'])) {
                $child = Task::query()
                    ->where('family_id', $parent->family_id)
                    ->where('parent_id', $parent->id)
                    ->whereKey($sub['id'])
                    ->first();
            }

            $attrs = $this->attributesForSubtask($parent, $sub);

            if ($child === null) {
                $child = Task::query()->create([
                    ...$attrs,
                    'family_id' => $parent->family_id,
                    'parent_id' => $parent->id,
                    'title' => $sub['title'],
                    'version' => 1,
                    'created_by' => $parent->created_by,
                ]);
            } else {
                $child->fill([
                    ...$attrs,
                    'title' => $sub['title'],
                ]);
                $child->version++;
                $child->save();
            }

            $this->syncSchedules($child, $this->schedulesForSubtask($parent, $sub));
            $this->refreshPendingInstances($child);
            $keptIds[] = $child->id;
        }

        Task::query()
            ->where('parent_id', $parent->id)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function propagateToChildren(Task $parent): void
    {
        $children = Task::query()->where('parent_id', $parent->id)->get();
        foreach ($children as $child) {
            $child->fill($this->sharedParentAttributes($parent));
            $child->version++;
            $child->save();
            $this->refreshPendingInstances($child);
        }
    }

    /**
     * Fields copied from the group title task onto every subtask.
     *
     * @return array<string, mixed>
     */
    private function sharedParentAttributes(Task $parent): array
    {
        return [
            'child_id' => $parent->child_id,
            'category' => $parent->category,
            'category_id' => $parent->category_id,
            'kind' => TaskKind::Standard,
            'requires_approval' => $parent->requires_approval,
            'requires_self_check' => $parent->requires_self_check,
            'is_active' => $parent->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $sub
     * @return array<string, mixed>
     */
    private function attributesForSubtask(Task $parent, array $sub): array
    {
        return [
            ...$this->sharedParentAttributes($parent),
            'start_date' => $sub['start_date'] ?? $parent->start_date,
            'end_date' => array_key_exists('end_date', $sub) ? $sub['end_date'] : $parent->end_date,
            'xp_reward' => $sub['xp_reward'] ?? $parent->xp_reward,
            'coin_reward' => $sub['coin_reward'] ?? $parent->coin_reward,
            'with_parent' => array_key_exists('with_parent', $sub)
                ? (bool) $sub['with_parent']
                : $parent->with_parent,
        ];
    }

    /**
     * @param  array<string, mixed>  $sub
     * @return list<array<string, mixed>>
     */
    private function schedulesForSubtask(Task $parent, array $sub): array
    {
        if (! empty($sub['schedules']) && is_array($sub['schedules'])) {
            return array_values($sub['schedules']);
        }

        $parent->load('schedules');
        $base = $parent->schedules->first();
        $frequency = $base?->frequency?->value ?? ScheduleFrequency::Once->value;
        $dueTime = $sub['due_time'] ?? null;

        if (is_string($dueTime) && strlen($dueTime) >= 5) {
            $dueTime = substr($dueTime, 0, 5);
        } else {
            $dueTime = null;
        }

        return [[
            'frequency' => $frequency,
            'weekdays' => $base?->weekdays,
            'dates' => $base?->dates,
            'due_time' => $dueTime,
        ]];
    }

    private function present(Task $task): Task
    {
        return $task->load(['steps', 'schedules', 'taskCategory', 'children.schedules', 'children.steps']);
    }

    private function refreshPendingInstances(Task $task): void
    {
        $task->unsetRelation('schedules');
        $task->unsetRelation('steps');
        $task->unsetRelation('parent');
        $snapshot = $task->instanceSnapshot();

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
