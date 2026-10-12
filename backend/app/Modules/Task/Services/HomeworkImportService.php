<?php

namespace App\Modules\Task\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\User;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskKind;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HomeworkImportService
{
    public function __construct(private readonly TaskInstanceGenerator $generator) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{task: Task, instances: Collection<int, TaskInstance>}
     */
    public function confirm(Family $family, User $parent, array $payload): array
    {
        $childId = (int) $payload['child_id'];
        $child = ChildProfile::query()
            ->where('family_id', $family->id)
            ->whereKey($childId)
            ->first();

        if ($child === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('孩子不存在', 'Child not found'), 404);
        }

        $items = $payload['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('至少需要一条作业条目', 'At least one homework item is required'), 422);
        }

        $taskDate = (string) $payload['task_date'];
        $subject = (string) $payload['subject'];
        $title = mb_substr(trim((string) $payload['title']), 0, 128);
        $description = $this->buildDescription($subject, $payload['source_text'] ?? null, $items);

        $result = DB::transaction(function () use ($family, $parent, $child, $payload, $items, $taskDate, $subject, $title, $description) {
            $withParent = (bool) ($payload['with_parent'] ?? false);
            $task = Task::query()->create([
                'family_id' => $family->id,
                'child_id' => $child->id,
                'category' => $subject,
                'title' => $title,
                'icon' => null,
                'description' => $description,
                'kind' => TaskKind::Standard,
                'start_date' => $taskDate,
                'end_date' => $taskDate,
                'estimated_minutes' => null,
                'xp_reward' => (int) ($payload['xp_reward'] ?? 0),
                'coin_reward' => (int) ($payload['coin_reward'] ?? 0),
                'requires_approval' => (bool) ($payload['requires_approval'] ?? true),
                'requires_self_check' => (bool) ($payload['requires_self_check'] ?? false),
                'with_parent' => $withParent,
                'is_active' => true,
                'version' => 1,
                'created_by' => $parent->id,
            ]);

            $childIds = [];
            foreach (array_values($items) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $childTask = Task::query()->create([
                    'family_id' => $family->id,
                    'parent_id' => $task->id,
                    'child_id' => $child->id,
                    'category' => $subject,
                    'title' => $this->itemTitle($item),
                    'icon' => null,
                    'description' => $this->itemDescription($item),
                    'kind' => TaskKind::Standard,
                    'start_date' => $taskDate,
                    'end_date' => $taskDate,
                    'estimated_minutes' => null,
                    'xp_reward' => (int) ($item['suggested_xp'] ?? 0),
                    'coin_reward' => (int) ($item['suggested_coins'] ?? 0),
                    'requires_approval' => (bool) ($payload['requires_approval'] ?? true),
                    'requires_self_check' => (bool) ($payload['requires_self_check'] ?? false),
                    'with_parent' => $withParent,
                    'is_active' => true,
                    'version' => 1,
                    'created_by' => $parent->id,
                ]);
                TaskSchedule::query()->create([
                    'task_id' => $childTask->id,
                    'frequency' => ScheduleFrequency::Once,
                    'weekdays' => null,
                    'dates' => null,
                    'due_time' => null,
                ]);
                $childIds[] = $childTask->id;
            }

            $task->load(['steps', 'schedules', 'children.schedules', 'children.steps']);
            $childIdSet = array_fill_keys($childIds, true);
            $instances = $this->generator->ensureForChildOnDate($family, $child, $taskDate)
                ->filter(fn (TaskInstance $inst) => isset($childIdSet[(int) $inst->task_id]))
                ->values();

            return [
                'task' => $task,
                'instances' => $instances,
            ];
        });

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function buildDescription(string $subject, mixed $sourceText, array $items): string
    {
        $parts = ["学科：{$subject}", '来源：作业导入'];
        if (is_string($sourceText) && trim($sourceText) !== '') {
            $parts[] = "原文：\n".mb_substr(trim($sourceText), 0, 2000);
        } else {
            $lines = [];
            foreach ($items as $i => $item) {
                $line = ($i + 1).'. '.trim((string) ($item['title'] ?? ''));
                $detail = isset($item['detail']) ? trim((string) $item['detail']) : '';
                if ($detail !== '') {
                    $line .= ' — '.$detail;
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemTitle(array $item): string
    {
        return mb_substr(trim((string) ($item['title'] ?? '')), 0, 128);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemDescription(array $item): ?string
    {
        $detail = isset($item['detail']) ? trim((string) $item['detail']) : '';

        return $detail === '' ? null : mb_substr($detail, 0, 2000);
    }
}
