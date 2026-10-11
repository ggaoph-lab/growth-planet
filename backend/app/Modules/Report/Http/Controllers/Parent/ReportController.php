<?php

namespace App\Modules\Report\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Report\Services\GrowthReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly GrowthReportService $reports,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $child = $this->context->findChild((int) $request->query('child_id'));
        $date = $request->query('date') ?? $this->clock->today($this->context->family()->timezone)->toDateString();

        return response()->json(['data' => $this->reports->daily($child, $date)]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $child = $this->context->findChild((int) $request->query('child_id'));
        $weekStart = $request->query('week_start');
        if (! $weekStart) {
            [$start] = $this->clock->weekRange($this->context->family()->timezone);
            $weekStart = $start->setTimezone($this->context->family()->timezone)->toDateString();
        }

        return response()->json(['data' => $this->reports->weekly($child, $weekStart)]);
    }

    public function growth(Request $request): JsonResponse
    {
        $child = $this->context->findChild((int) $request->query('child_id'));

        return response()->json(['data' => $this->reports->growthSummary($child)]);
    }
}
