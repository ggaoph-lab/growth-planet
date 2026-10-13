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
use Illuminate\Support\Collection;

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
        $this->flow->retireTaskApprovals($family->id, $child->id);
        $instances = $this->generator->ensureForChildOnDate($family, $child, $date);

        return response()->json(['data' => $this->presentMany($instances)->values()]);
    }

    public function ready(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->present($this->flow->ready($instance, $this->context->child()))]);
    }

    public function firstStep(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->present($this->flow->markFirstStep($instance, $this->context->child()))]);
    }

    public function checkStep(int $id, int $sid, Request $request): JsonResponse
    {
        $data = $request->validate(['checked' => ['sometimes', 'boolean']]);
        $instance = $this->findInstance($id);
        $step = TaskInstanceStep::query()->where('task_instance_id', $instance->id)->findOrFail($sid);
        $this->flow->checkStep(
            $instance,
            $step,
            $this->context->child(),
            array_key_exists('checked', $data) ? (bool) $data['checked'] : true,
        );

        // Return the whole instance (with steps) so the kid UI can refresh checkmarks in one shot.
        return response()->json(['data' => $this->present($instance->fresh())]);
    }

    public function selfCheck(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->present($this->flow->selfCheck($instance, $this->context->child()))]);
    }

    public function submit(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->present($this->flow->submit($instance, $this->context->child(), $data['note'] ?? null))]);
    }

    public function withdraw(int $id): JsonResponse
    {
        $instance = $this->findInstance($id);

        return response()->json(['data' => $this->present($this->flow->withdraw(
            $instance,
            $this->context->child(),
            $this->context->user()->id,
        ))]);
    }

    private function findInstance(int $id): TaskInstance
    {
        $child = $this->context->child();

        return TaskInstance::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);
    }

    private function present(TaskInstance $instance): TaskInstance
    {
        $instance->load('instanceSteps');
        $instance->setAttribute('can_withdraw', $this->flow->canWithdraw($instance));

        return $instance;
    }

    /**
     * @param  Collection<int, TaskInstance>  $instances
     * @return Collection<int, TaskInstance>
     */
    private function presentMany(Collection $instances): Collection
    {
        $instances->each(fn (TaskInstance $instance) => $instance->load('instanceSteps'));
        $open = $this->flow->withdrawableIds($instances)->flip();
        $instances->each(fn (TaskInstance $instance) => $instance->setAttribute('can_withdraw', $open->has($instance->id)));

        return $instances;
    }
}
