<?php

namespace App\Modules\Schedule\Http\Controllers\Kid;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Schedule\Services\ScheduleResolver;
use App\Modules\Schedule\Services\SchoolScheduleService;
use Illuminate\Http\JsonResponse;

class ScheduleController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ScheduleResolver $resolver,
        private readonly SchoolScheduleService $schedules,
        private readonly Clock $clock,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $family = $this->context->family();
        $today = $this->clock->today($family->timezone);
        $tomorrow = $today->addDay();

        return response()->json([
            'data' => [
                'today' => $this->resolver->resolve($child, $family, $today->toDateString())->toArray(),
                'tomorrow' => $this->resolver->resolve($child, $family, $tomorrow->toDateString())->toArray(),
            ],
        ]);
    }

    public function week(): JsonResponse
    {
        $child = $this->context->child();
        $family = $this->context->family();
        $today = $this->clock->today($family->timezone)->toDateString();
        $schedule = $this->resolver->activeSchedule($child->id, $today);

        return response()->json([
            'data' => $this->schedules->serialize($schedule?->load('periods')),
        ]);
    }
}
