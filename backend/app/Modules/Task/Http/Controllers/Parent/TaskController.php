<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskKind;
use App\Modules\Task\Models\Task;
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
                'requires_approval' => $data['requires_approval'] ?? true,
                'requires_self_check' => $data['requires_self_check'] ?? false,
                'is_active' => true,
                'version' => 1,
                'created_by' => $this->context->user()->id,
            ]);

            foreach ($data['steps'] ?? [] as $i => $step) {
                TaskStep::query()->create([
                    'task_id' => $task->id,
                    'title' => $step['title'],
                    'is_required' => $step['is_required'] ?? true,
                    'is_self_check' => $step['is_self_check'] ?? false,
                    'sort' => $step['sort'] ?? $i,
                ]);
            }

            foreach ($data['schedules'] ?? [['frequency' => 'daily']] as $schedule) {
                TaskSchedule::query()->create([
                    'task_id' => $task->id,
                    'frequency' => $schedule['frequency'],
                    'weekdays' => $schedule['weekdays'] ?? null,
                    'dates' => $schedule['dates'] ?? null,
                    'due_time' => $schedule['due_time'] ?? null,
                ]);
            }

            return $task->load(['steps', 'schedules']);
        });

        return response()->json(['data' => $task], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $task = Task::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $this->validateTask($request, partial: true);
        $task->fill(collect($data)->except(['steps', 'schedules'])->all());
        $task->version++;
        $task->save();

        return response()->json(['data' => $task->fresh(['steps', 'schedules'])]);
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
        $prefix = $partial ? 'sometimes|' : '';

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
            'steps' => ['nullable', 'array'],
            'steps.*.title' => ['required_with:steps', 'string', 'max:128'],
            'schedules' => ['nullable', 'array'],
            'schedules.*.frequency' => ['required_with:schedules', Rule::enum(ScheduleFrequency::class)],
        ]);
    }
}
