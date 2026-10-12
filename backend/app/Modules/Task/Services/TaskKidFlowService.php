<?php

namespace App\Modules\Task\Services;

use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Task\Models\TaskSubmission;

class TaskKidFlowService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ApprovalService $approvals,
        private readonly RewardGrantService $rewards,
    ) {}

    public function ready(TaskInstance $instance, ChildProfile $child): TaskInstance
    {
        $this->assertChildInstance($instance, $child);
        if ($instance->ready_at !== null) {
            return $instance;
        }
        if (! in_array($instance->status, [TaskInstanceStatus::Pending, TaskInstanceStatus::InProgress, TaskInstanceStatus::Returned], true)) {
            throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能开始', 'Cannot start in the current state'), 409);
        }

        $instance->ready_at = $this->clock->now();
        $instance->status = TaskInstanceStatus::InProgress;
        $instance->save();

        return $instance;
    }

    public function markFirstStep(TaskInstance $instance, ChildProfile $child): TaskInstance
    {
        $this->assertChildInstance($instance, $child);
        if ($instance->first_step_at === null) {
            $instance->first_step_at = $this->clock->now();
            $instance->save();
        }

        return $instance;
    }

    public function checkStep(TaskInstance $instance, TaskInstanceStep $step, ChildProfile $child, bool $checked = true): TaskInstanceStep
    {
        $this->assertChildInstance($instance, $child);
        if ($step->task_instance_id !== $instance->id) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
        if (! in_array($instance->status, [TaskInstanceStatus::Pending, TaskInstanceStatus::InProgress, TaskInstanceStatus::Returned], true)) {
            throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能改勾选', 'Cannot change checklist in the current state'), 409);
        }
        $step->checked = $checked;
        $step->checked_at = $checked ? $this->clock->now() : null;
        $step->save();

        return $step;
    }

    public function selfCheck(TaskInstance $instance, ChildProfile $child): TaskInstance
    {
        $this->assertChildInstance($instance, $child);
        $instance->self_checked = true;
        $instance->save();

        return $instance;
    }

    public function submit(TaskInstance $instance, ChildProfile $child, ?string $note = null): TaskInstance
    {
        $this->assertChildInstance($instance, $child);
        if ($instance->status === TaskInstanceStatus::Submitted) {
            return $instance;
        }
        if (! in_array($instance->status, [TaskInstanceStatus::InProgress, TaskInstanceStatus::Returned], true)) {
            throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能提交', 'Cannot submit in the current state'), 409);
        }

        $snapshot = $instance->snapshot;
        if (($snapshot['requires_self_check'] ?? false) && ! $instance->self_checked) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请先完成自检', 'Please complete self-check first'), 422);
        }

        $attempt = (int) $instance->submissions()->max('attempt_no') + 1;
        TaskSubmission::query()->create([
            'task_instance_id' => $instance->id,
            'attempt_no' => $attempt,
            'note' => $note,
            'created_at' => $this->clock->now(),
        ]);

        $instance->status = TaskInstanceStatus::Submitted;
        $instance->submitted_at = $this->clock->now();
        $instance->save();

        if ($snapshot['requires_approval'] ?? true) {
            $this->approvals->createPending(
                $instance->family_id,
                $instance->child_id,
                ApprovalType::TaskCompletion,
                'task_instance',
                $instance->id,
                $child->member->user_id,
            );
        } else {
            $this->rewards->grant(new RewardIntent(
                familyId: $instance->family_id,
                childId: $instance->child_id,
                policyCode: 'task.complete',
                grantKey: 'task:instance:'.$instance->id.':complete',
                sourceModule: SourceModule::Task,
                sourceType: 'task_instance',
                sourceId: $instance->id,
                occurredAt: $this->clock->now(),
                xpAmount: (int) ($snapshot['xp_reward'] ?? 0),
                coinAmount: (int) ($snapshot['coin_reward'] ?? 0),
                postImmediately: true,
            ));
            $instance->status = TaskInstanceStatus::Completed;
            $instance->completed_at = $this->clock->now();
            $instance->save();
        }

        return $instance;
    }

    private function assertChildInstance(TaskInstance $instance, ChildProfile $child): void
    {
        if ($instance->child_id !== $child->id || $instance->family_id !== $child->family_id) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
    }
}
