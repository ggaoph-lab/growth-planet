<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\TaskInstanceGenerator;
use App\Modules\Task\Services\TaskKidFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskInstanceController
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
        $this->flow->retireTaskApprovals($family->id);
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
}
