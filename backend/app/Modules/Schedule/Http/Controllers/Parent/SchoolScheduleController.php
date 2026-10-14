<?php

namespace App\Modules\Schedule\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Schedule\Services\ScheduleResolver;
use App\Modules\Schedule\Services\SchoolScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolScheduleController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly SchoolScheduleService $schedules,
        private readonly ScheduleResolver $resolver,
        private readonly Clock $clock,
    ) {}

    public function show(int $childId): JsonResponse
    {
        $child = $this->context->findChild($childId);
        $today = $this->clock->today($this->context->family()->timezone)->toDateString();
        $schedule = $this->resolver->activeSchedule($child->id, $today);

        return response()->json(['data' => $this->schedules->serialize($schedule?->load('periods'))]);
    }

    public function update(int $childId, Request $request): JsonResponse
    {
        $child = $this->context->findChild($childId);
        $family = $this->context->family();
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:128'],
            'period_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'periods' => ['required', 'array'],
            'periods.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'periods.*.period_index' => ['required', 'integer', 'min:1', 'max:12'],
            'periods.*.subject' => ['required', 'string', 'max:64'],
            'periods.*.start_time' => ['nullable', 'date_format:H:i'],
            'periods.*.end_time' => ['nullable', 'date_format:H:i'],
            'periods.*.room' => ['nullable', 'string', 'max:64'],
        ]);

        $schedule = $this->schedules->putWeekly(
            $child,
            $data['title'] ?? '本学期课表',
            (int) ($data['period_count'] ?? 6),
            $data['effective_from'] ?? $this->clock->today($family->timezone)->toDateString(),
            $data['effective_to'] ?? null,
            $data['periods'],
        );

        return response()->json(['data' => $this->schedules->serialize($schedule)]);
    }

    public function resolveDay(int $childId, Request $request): JsonResponse
    {
        $child = $this->context->findChild($childId);
        $family = $this->context->family();
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $date = $data['date'] ?? $this->clock->today($family->timezone)->toDateString();

        return response()->json([
            'data' => $this->resolver->resolve($child, $family, $date)->toArray(),
        ]);
    }
}
