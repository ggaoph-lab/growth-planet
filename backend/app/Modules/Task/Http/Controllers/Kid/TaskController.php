<?php

namespace App\Modules\Task\Http\Controllers\Kid;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use App\Modules\Task\Services\TaskInstanceGenerator;
use App\Modules\Task\Services\TaskKidFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly TaskInstanceGenerator $generator,
        private readonly TaskKidFlowService $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $family = $this->context->family();
        $child = $this->context->child();
        $date = $request->query('date', $this->clock->today($family->timezone)->toDateString());
        $instances = $this->generator->ensureForChildOnDate($family, $child, $date);
        $instances->each(fn ($instance) => $instance->load('instanceSteps'));

        return response()->json(['data' => $instances->values()]);
    }

    public function ready(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->flow->ready($instance, $this->context->child())]);
    }

    public function firstStep(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->flow->markFirstStep($instance, $this->context->child())]);
    }

    public function checkStep(int $id, int $sid): JsonResponse
    {
        $instance = $this->findInstance($id);
        $step = TaskInstanceStep::query()->where('task_instance_id', $instance->id)->findOrFail($sid);

        return response()->json(['data' => $this->flow->checkStep($instance, $step, $this->context->child())]);
    }

    public function selfCheck(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->flow->selfCheck($instance, $this->context->child())]);
    }

    public function submit(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->flow->submit($instance, $this->context->child(), $data['note'] ?? null)]);
    }

    private function findInstance(int $id): TaskInstance
    {
        $child = $this->context->child();

        return TaskInstance::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);
    }
}
