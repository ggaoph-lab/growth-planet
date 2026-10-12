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
use App\Modules\Task\Models\TaskStep;
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
            $task = Task::query()->create([
                'family_id' => $family->id,
                'child_id' => $child->id,
                'category' => $subject,
                'title' => $title,
                'icon' => null,
                'description' => $description,
                'kind' => TaskKind::Stepped,
                'start_date' => $taskDate,
                'end_date' => $taskDate,
                'estimated_minutes' => null,
                'xp_reward' => (int) ($payload['xp_reward'] ?? 0),
                'coin_reward' => (int) ($payload['coin_reward'] ?? 0),
                'requires_approval' => (bool) ($payload['requires_approval'] ?? true),
                'requires_self_check' => (bool) ($payload['requires_self_check'] ?? false),
                'is_active' => true,
                'version' => 1,
                'created_by' => $parent->id,
            ]);

            foreach (array_values($items) as $i => $item) {
                $stepTitle = trim((string) ($item['title'] ?? ''));
                $detail = isset($item['detail']) ? trim((string) $item['detail']) : '';
                if ($detail !== '') {
                    $stepTitle = $stepTitle.'：'.$detail;
                }
                TaskStep::query()->create([
                    'task_id' => $task->id,
                    'title' => mb_substr($stepTitle, 0, 128),
                    'is_required' => true,
                    'is_self_check' => false,
                    'sort' => $i,
                ]);
            }

            TaskSchedule::query()->create([
                'task_id' => $task->id,
                'frequency' => ScheduleFrequency::Once,
                'weekdays' => null,
                'dates' => null,
                'due_time' => null,
            ]);

            $task->load(['steps', 'schedules']);
            $instance = $this->generator->ensureForChildOnDate($family, $child, $taskDate)
                ->first(fn (TaskInstance $inst) => $inst->task_id === $task->id);

            return [
                'task' => $task,
                'instances' => collect($instance ? [$instance] : []),
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
}
