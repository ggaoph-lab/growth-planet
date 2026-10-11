<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Services\TaskInstanceGenerator;
use App\Modules\Task\Services\TaskParentDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskInstanceController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly TaskInstanceGenerator $generator,
        private readonly TaskParentDecisionService $decisions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $family = $this->context->family();
        $date = $request->query('date', $this->clock->today($family->timezone)->toDateString());
        $childId = $request->query('child_id');
        $children = $childId
            ? collect([$this->context->findChild((int) $childId)])
            : $this->context->family()->children()->get();

        $instances = collect();
        foreach ($children as $child) {
            $generated = $this->generator->ensureForChildOnDate($family, $child, $date);
            $generated->each(fn ($instance) => $instance->load('instanceSteps'));
            $instances = $instances->merge($generated);
        }

        return response()->json(['data' => $instances->values()]);
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        $instance = TaskInstance::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        $result = $this->decisions->approve($instance, (int) $data['lock_version']);

        return response()->json(['data' => $result]);
    }

    public function returnInstance(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $instance = TaskInstance::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        $result = $this->decisions->return($instance, (int) $data['lock_version'], $data['note'] ?? null);

        return response()->json(['data' => $result]);
    }
}
