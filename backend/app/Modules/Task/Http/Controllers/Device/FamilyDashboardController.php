<?php

namespace App\Modules\Task\Http\Controllers\Device;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Ledger\Support\LedgerEntryPresenter;
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

        $entries = XpLedgerEntry::query()->where('family_id', $family->id)
            ->where('business_date', $date)->where('amount', '>', 0)
            ->latest('created_at')->limit(20)->get();
        $presented = collect(LedgerEntryPresenter::collection($entries, 'xp'))->keyBy('ulid');
        $childNames = $children->pluck('nickname', 'id');
        $activity = $entries->map(function (XpLedgerEntry $entry) use ($presented, $childNames, $family) {
            $detail = $presented->get($entry->ulid, []);
            $module = $entry->source_module->value;
            $label = match ($module) {
                'task' => '完成任务', 'habit' => '完成习惯', 'english' => '英语学习',
                'poetry' => '诗词学习', 'encyclopedia' => '百科探索', 'exploration' => '探索学习',
                'game' => '游戏活动', 'ielts' => '英语学习', default => '成长活动',
            };
            return [
                'child' => $childNames[$entry->child_id] ?? '',
                'title' => $label.(! empty($detail['source_name']) ? ' · '.$detail['source_name'] : ''),
                'time' => $entry->created_at?->timezone($family->timezone)->format('H:i'),
                'xp' => $entry->amount,
            ];
        })->values()->all();

        return response()->json(['data' => ['date' => $date, 'tasks' => $rows, 'activity' => $activity]]);
    }
}
