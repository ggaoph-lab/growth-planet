<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Enums\TaskKind;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use App\Modules\Task\Models\TaskSchedule;
use App\Modules\Task\Models\TaskStep;
use App\Modules\Task\Services\TaskInstanceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly TaskInstanceGenerator $generator,
    ) {}

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
                'requires_approval' => false,
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
        $editScope = $data['edit_scope'] ?? null;
        $scopeDate = isset($data['scope_date']) ? (string) $data['scope_date'] : null;

        $task = DB::transaction(function () use ($task, $data, $editScope, $scopeDate) {
            if ($editScope === 'this_day') {
                $this->patchThisDayInstances($task, $data, (string) $scopeDate);

                return $this->present($task->fresh() ?? $task);
            }

            $family = $this->context->family();
            $oldWeekdaysByTaskId = $this->collectLeafWeekdays($task);
            $onlyWeekday = $editScope === 'weekday_forward'
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $scopeDate, $family->timezone)->isoWeekday()
                : null;
            $fromDate = $editScope === 'weekday_forward' ? $scopeDate : null;

            $task->fill(collect($data)->except([
                'steps',
                'schedules',
                'subtasks',
                'requires_approval',
                'edit_scope',
                'scope_date',
            ])->all());
            $task->requires_approval = false;
            $task->version++;
            $task->save();

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($task, $data['steps'] ?? []);
            }
            if (array_key_exists('schedules', $data)) {
                $this->syncSchedules($task, $data['schedules'] ?? []);
            }
            if (array_key_exists('subtasks', $data) && $task->parent_id === null) {
                $this->syncSubtasks($task, $data['subtasks'] ?? [], $fromDate, $onlyWeekday, $family->timezone);
            } elseif ($task->parent_id === null) {
                $this->propagateToChildren($task, $fromDate, $onlyWeekday, $family->timezone);
            }

            if ($task->children()->exists()) {
                TaskInstance::query()
                    ->where('task_id', $task->id)
                    ->where('status', TaskInstanceStatus::Pending)
                    ->delete();

                if ($editScope === 'weekday_forward') {
                    $this->cleanupRemovedWeekdaysForChildren(
                        $task,
                        $oldWeekdaysByTaskId,
                        (string) $scopeDate,
                        $family->timezone,
                    );
                }
            } else {
                if ($editScope === 'weekday_forward') {
                    $newWeekdays = $this->weekdaysFromSchedules($task->fresh()?->schedules ?? $task->schedules);
                    $removed = array_values(array_diff($oldWeekdaysByTaskId[$task->id] ?? [], $newWeekdays));
                    if ($removed !== []) {
                        $this->deletePendingForWeekdays($task, (string) $scopeDate, $removed, $family->timezone);
                    }
                }
                $this->refreshPendingInstances($task, $fromDate, $onlyWeekday, $family->timezone);
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

        $data = $request->validate([
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
            'edit_scope' => ['nullable', Rule::in(['this_day', 'weekday_forward'])],
            'scope_date' => ['required_with:edit_scope', 'nullable', 'date'],
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
            'subtasks.*.description' => ['nullable', 'string', 'max:2000'],
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

        $this->assertWeeklySchedulesHaveWeekdays($data['schedules'] ?? null, 'schedules');
        foreach ($data['subtasks'] ?? [] as $i => $sub) {
            $this->assertWeeklySchedulesHaveWeekdays($sub['schedules'] ?? null, "subtasks.$i.schedules");
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>|null  $schedules
     */
    private function assertWeeklySchedulesHaveWeekdays(?array $schedules, string $prefix): void
    {
        if ($schedules === null) {
            return;
        }

        foreach ($schedules as $i => $schedule) {
            $frequency = $schedule['frequency'] ?? null;
            $frequencyValue = $frequency instanceof ScheduleFrequency
                ? $frequency->value
                : (string) $frequency;
            if ($frequencyValue !== ScheduleFrequency::Weekly->value) {
                continue;
            }
            $weekdays = $schedule['weekdays'] ?? [];
            if (! is_array($weekdays) || $weekdays === []) {
                throw ValidationException::withMessages([
                    "{$prefix}.{$i}.weekdays" => ['Weekly schedules require at least one weekday (1–7).'],
                ]);
            }
        }
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
            $weekdays = $schedule['weekdays'] ?? null;
            if (is_array($weekdays)) {
                $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
                sort($weekdays);
            }
            TaskSchedule::query()->create([
                'task_id' => $task->id,
                'frequency' => $schedule['frequency'],
                'weekdays' => $weekdays,
                'dates' => $schedule['dates'] ?? null,
                'due_time' => $dueTime,
            ]);
        }
        $task->unsetRelation('schedules');
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function syncSubtasks(
        Task $parent,
        array $subtasks,
        ?string $fromDate = null,
        ?int $onlyWeekday = null,
        ?string $timezone = null,
    ): void {
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
                    'description' => $sub['description'] ?? null,
                    'version' => 1,
                    'created_by' => $parent->created_by,
                ]);
            } else {
                $child->fill([
                    ...$attrs,
                    'title' => $sub['title'],
                    'description' => array_key_exists('description', $sub) ? $sub['description'] : $child->description,
                ]);
                $child->version++;
                $child->save();
            }

            $this->syncSchedules($child, $this->schedulesForSubtask($parent, $sub));
            $this->refreshPendingInstances($child, $fromDate, $onlyWeekday, $timezone);
            $keptIds[] = $child->id;
        }

        Task::query()
            ->where('parent_id', $parent->id)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function propagateToChildren(
        Task $parent,
        ?string $fromDate = null,
        ?int $onlyWeekday = null,
        ?string $timezone = null,
    ): void {
        $children = Task::query()->where('parent_id', $parent->id)->get();
        foreach ($children as $child) {
            $child->fill($this->sharedParentAttributes($parent));
            $child->version++;
            $child->save();
            $this->refreshPendingInstances($child, $fromDate, $onlyWeekday, $timezone);
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function patchThisDayInstances(Task $task, array $data, string $scopeDate): void
    {
        $family = $this->context->family();

        if (! empty($data['subtasks']) && $task->parent_id === null) {
            $task->loadMissing('children');
            if ($task->children->isEmpty()) {
                $sub = $data['subtasks'][0] ?? null;
                if (is_array($sub)) {
                    $this->ensureAndPatchLeafForDate(
                        $task,
                        $family,
                        $scopeDate,
                        $this->snapshotOverlayFromPayload($task, $sub),
                    );
                }

                return;
            }

            foreach ($data['subtasks'] as $sub) {
                if (empty($sub['id']) || ! is_array($sub)) {
                    continue;
                }
                $child = Task::query()
                    ->where('family_id', $task->family_id)
                    ->where('parent_id', $task->id)
                    ->whereKey($sub['id'])
                    ->first();
                if ($child === null) {
                    continue;
                }
                $this->ensureAndPatchLeafForDate($child, $family, $scopeDate, $this->snapshotOverlayFromPayload($child, $sub));
            }

            return;
        }

        $this->ensureAndPatchLeafForDate($task, $family, $scopeDate, $this->snapshotOverlayFromPayload($task, $data));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function snapshotOverlayFromPayload(Task $task, array $payload): array
    {
        $snapshot = $task->instanceSnapshot();

        if (array_key_exists('title', $payload) && is_string($payload['title'])) {
            $snapshot['title'] = $payload['title'];
        }
        if (array_key_exists('description', $payload)) {
            $snapshot['description'] = $payload['description'];
        }
        if (array_key_exists('xp_reward', $payload)) {
            $snapshot['xp_reward'] = (int) $payload['xp_reward'];
        }
        if (array_key_exists('coin_reward', $payload)) {
            $snapshot['coin_reward'] = (int) $payload['coin_reward'];
        }
        if (array_key_exists('with_parent', $payload)) {
            $snapshot['with_parent'] = (bool) $payload['with_parent'];
        }

        $dueTime = null;
        if (! empty($payload['schedules'][0]['due_time']) && is_string($payload['schedules'][0]['due_time'])) {
            $dueTime = substr($payload['schedules'][0]['due_time'], 0, 5);
        } elseif (! empty($payload['due_time']) && is_string($payload['due_time'])) {
            $dueTime = substr($payload['due_time'], 0, 5);
        }
        if ($dueTime !== null) {
            $snapshot['due_time'] = strlen($dueTime) === 5 ? $dueTime.':00' : $dueTime;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function ensureAndPatchLeafForDate(Task $task, Family $family, string $scopeDate, array $snapshot): void
    {
        $children = $this->targetChildrenForTask($task, $family);
        foreach ($children as $child) {
            $this->generator->ensureForChildOnDate($family, $child, $scopeDate);
        }

        $instances = TaskInstance::query()
            ->where('task_id', $task->id)
            ->whereDate('task_date', $scopeDate)
            ->where('status', TaskInstanceStatus::Pending)
            ->get();

        foreach ($instances as $instance) {
            $this->applySnapshotToInstance($instance, $snapshot, $task->version);
        }
    }

    /**
     * @return Collection<int, ChildProfile>
     */
    private function targetChildrenForTask(Task $task, Family $family)
    {
        $query = ChildProfile::query()
            ->where('family_id', $family->id)
            ->where('status', ChildStatus::Active);

        if ($task->child_id !== null) {
            $query->whereKey($task->child_id);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function applySnapshotToInstance(TaskInstance $instance, array $snapshot, int $taskVersion): void
    {
        $instance->snapshot = $snapshot;
        $instance->task_version = $taskVersion;
        $instance->save();

        TaskInstanceStep::query()->where('task_instance_id', $instance->id)->delete();
        foreach ($snapshot['steps'] ?? [] as $step) {
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

    private function refreshPendingInstances(
        Task $task,
        ?string $fromDate = null,
        ?int $onlyWeekday = null,
        ?string $timezone = null,
    ): void {
        $task->unsetRelation('schedules');
        $task->unsetRelation('steps');
        $task->unsetRelation('parent');
        $snapshot = $task->instanceSnapshot();

        $instances = TaskInstance::query()
            ->where('task_id', $task->id)
            ->where('status', TaskInstanceStatus::Pending)
            ->when($fromDate !== null, fn ($q) => $q->whereDate('task_date', '>=', $fromDate))
            ->get();

        $tz = $timezone ?? $this->context->family()->timezone;

        foreach ($instances as $instance) {
            if ($onlyWeekday !== null) {
                $date = $instance->task_date?->toDateString();
                if ($date === null) {
                    continue;
                }
                $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz)->isoWeekday();
                if ($weekday !== $onlyWeekday) {
                    continue;
                }
            }

            $this->applySnapshotToInstance($instance, $snapshot, $task->version);
        }
    }

    /**
     * @return array<int, list<int>>
     */
    private function collectLeafWeekdays(Task $task): array
    {
        $task->loadMissing(['schedules', 'children.schedules']);
        $map = [];

        if ($task->children->isNotEmpty()) {
            foreach ($task->children as $child) {
                $map[(int) $child->id] = $this->weekdaysFromSchedules($child->schedules);
            }

            return $map;
        }

        $map[(int) $task->id] = $this->weekdaysFromSchedules($task->schedules);

        return $map;
    }

    /**
     * @param  Collection<int, TaskSchedule>|iterable<int, TaskSchedule>  $schedules
     * @return list<int>
     */
    private function weekdaysFromSchedules(iterable $schedules): array
    {
        $weekdays = [];
        foreach ($schedules as $schedule) {
            if ($schedule->frequency !== ScheduleFrequency::Weekly) {
                continue;
            }
            foreach ($schedule->weekdays ?? [] as $day) {
                $weekdays[] = (int) $day;
            }
        }

        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return $weekdays;
    }

    /**
     * @param  array<int, list<int>>  $oldWeekdaysByTaskId
     */
    private function cleanupRemovedWeekdaysForChildren(
        Task $parent,
        array $oldWeekdaysByTaskId,
        string $scopeDate,
        string $timezone,
    ): void {
        $parent->load('children.schedules');
        foreach ($parent->children as $child) {
            $old = $oldWeekdaysByTaskId[(int) $child->id] ?? [];
            $new = $this->weekdaysFromSchedules($child->schedules);
            $removed = array_values(array_diff($old, $new));
            if ($removed === []) {
                continue;
            }
            $this->deletePendingForWeekdays($child, $scopeDate, $removed, $timezone);
        }
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function deletePendingForWeekdays(
        Task $task,
        string $fromDate,
        array $weekdays,
        string $timezone,
    ): void {
        if ($weekdays === []) {
            return;
        }

        $instances = TaskInstance::query()
            ->where('task_id', $task->id)
            ->where('status', TaskInstanceStatus::Pending)
            ->whereDate('task_date', '>=', $fromDate)
            ->get();

        foreach ($instances as $instance) {
            $date = $instance->task_date?->toDateString();
            if ($date === null) {
                continue;
            }
            $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->isoWeekday();
            if (in_array($weekday, $weekdays, true)) {
                $instance->delete();
            }
        }
    }
}
