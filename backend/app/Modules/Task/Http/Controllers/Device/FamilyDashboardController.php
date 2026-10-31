<?php

namespace App\Modules\Task\Http\Controllers\Device;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Task\Services\TaskInstanceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyDashboardController
{
    public function __construct(
        private readonly DeviceTokens $tokens,
        private readonly Clock $clock,
        private readonly TaskInstanceGenerator $generator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $device = $this->tokens->fromRequest($request);
        abort_if($device === null, 401);
        $family = Family::query()->findOrFail($device->family_id);
        $date = $this->clock->today($family->timezone)->toDateString();
        $children = $family->children()->where('status', ChildStatus::Active->value)->orderBy('id')->get();
        $rows = [];
        foreach ($children as $child) {
            $tasks = $this->generator->ensureForChildOnDate($family, $child, $date);
            foreach ($tasks as $task) {
                if ($task->status->value === 'cancelled') continue;
                $rows[] = [
                    'child' => $child->nickname,
                    'title' => (string) ($task->snapshot['title'] ?? ''),
                    'status' => $task->status->value,
                ];
            }
        }

        return response()->json(['data' => ['date' => $date, 'tasks' => $rows]]);
    }
}
