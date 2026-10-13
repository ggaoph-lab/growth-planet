<?php

namespace App\Modules\Task\Services;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Models\TaskInstanceStep;
use App\Modules\Task\Models\TaskSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($instance, $child, $note) {
            $locked = TaskInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $this->assertChildInstance($locked, $child);
            if ($locked->status === TaskInstanceStatus::Completed) {
                return $locked;
            }
            if ($locked->status === TaskInstanceStatus::Submitted) {
                $this->completeAndPay($locked);
                $this->cancelPendingApproval($locked, $child->member->user_id);

                return $locked;
            }
            if (! in_array($locked->status, [TaskInstanceStatus::InProgress, TaskInstanceStatus::Returned], true)) {
                throw new ApiException('INVALID_STATE', BiMsg::of('当前状态不能提交', 'Cannot submit in the current state'), 409);
            }

            $snapshot = $locked->snapshot;
            if (($snapshot['requires_self_check'] ?? false) && ! $locked->self_checked) {
                throw new ApiException('VALIDATION_FAILED', BiMsg::of('请先完成自检', 'Please complete self-check first'), 422);
            }

            $attempt = (int) $locked->submissions()->max('attempt_no') + 1;
            TaskSubmission::query()->create([
                'task_instance_id' => $locked->id,
                'attempt_no' => $attempt,
                'note' => $note,
                'created_at' => $this->clock->now(),
            ]);

            $this->completeAndPay($locked);
            $this->cancelPendingApproval($locked, $child->member->user_id);

            return $locked;
        });
    }

    /**
     * Parent-created tasks no longer wait in the approval center.
     * Finish any completion that is still pending, pay it, and close the request.
     */
    public function retireTaskApprovals(int $familyId, ?int $childId = null): void
    {
        $query = ApprovalRequest::query()
            ->where('family_id', $familyId)
            ->where('type', ApprovalType::TaskCompletion)
            ->where('status', ApprovalStatus::Pending);
        if ($childId !== null) {
            $query->where('child_id', $childId);
        }

        foreach ($query->pluck('id') as $id) {
            DB::transaction(function () use ($id) {
                $approval = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();
                if ($approval === null || $approval->status !== ApprovalStatus::Pending) {
                    return;
                }

                $instance = TaskInstance::query()->whereKey($approval->subject_id)->lockForUpdate()->first();
                if ($instance !== null && in_array($instance->status, [TaskInstanceStatus::Submitted, TaskInstanceStatus::Returned], true)) {
                    $this->completeAndPay($instance);
                }
                $this->approvals->cancel($approval, null);
            });
        }

        $instances = TaskInstance::query()
            ->where('family_id', $familyId)
            ->where('status', TaskInstanceStatus::Submitted);
        if ($childId !== null) {
            $instances->where('child_id', $childId);
        }

        foreach ($instances->pluck('id') as $id) {
            DB::transaction(function () use ($id) {
                $instance = TaskInstance::query()->whereKey($id)->lockForUpdate()->first();
                if ($instance !== null && $instance->status === TaskInstanceStatus::Submitted) {
                    $this->completeAndPay($instance);
                }
            });
        }
    }

    /**
     * Kid undoes a completion and the reward that was paid with it.
     */
    public function withdraw(TaskInstance $instance, ChildProfile $child, int $actorUserId): TaskInstance
    {
        return DB::transaction(function () use ($instance, $child, $actorUserId) {
            $pending = ApprovalRequest::query()
                ->where('type', ApprovalType::TaskCompletion)
                ->where('subject_type', 'task_instance')
                ->where('subject_id', $instance->id)
                ->where('status', ApprovalStatus::Pending)
                ->lockForUpdate()
                ->first();

            $locked = TaskInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $this->assertChildInstance($locked, $child);

            if ($locked->status === TaskInstanceStatus::Submitted) {
                if ($pending !== null) {
                    $this->approvals->cancel($pending, $actorUserId);
                }
            } elseif ($locked->status === TaskInstanceStatus::Completed && ! $this->hasApprovedCompletion($locked)) {
                if ($pending !== null) {
                    $this->approvals->cancel($pending, $actorUserId);
                }
                $this->reverseOpenGrants($locked, $actorUserId);
            } else {
                throw new ApiException('INVALID_STATE', BiMsg::of('只能取消刚刚完成的任务', 'Only a just-finished task can be undone'), 409);
            }

            $locked->status = TaskInstanceStatus::Pending;
            $locked->ready_at = null;
            $locked->first_step_at = null;
            $locked->submitted_at = null;
            $locked->completed_at = null;
            $locked->self_checked = false;
            $locked->save();

            return $locked;
        });
    }

    public function canWithdraw(TaskInstance $instance): bool
    {
        if ($instance->status === TaskInstanceStatus::Submitted) {
            return true;
        }

        return $instance->status === TaskInstanceStatus::Completed && ! $this->hasApprovedCompletion($instance);
    }

    /**
     * @param  Collection<int, TaskInstance>  $instances
     * @return Collection<int, int>
     */
    public function withdrawableIds(Collection $instances): Collection
    {
        $completedIds = $instances
            ->filter(fn (TaskInstance $instance) => $instance->status === TaskInstanceStatus::Completed)
            ->pluck('id');

        $approved = $completedIds->isEmpty()
            ? collect()
            : ApprovalRequest::query()
                ->where('type', ApprovalType::TaskCompletion)
                ->where('subject_type', 'task_instance')
                ->whereIn('subject_id', $completedIds)
                ->where('status', ApprovalStatus::Approved)
                ->pluck('subject_id');

        return $instances
            ->filter(function (TaskInstance $instance) use ($approved) {
                if ($instance->status === TaskInstanceStatus::Submitted) {
                    return true;
                }
                if ($instance->status === TaskInstanceStatus::Completed) {
                    return ! $approved->contains($instance->id);
                }

                return false;
            })
            ->pluck('id')
            ->values();
    }

    private function completeAndPay(TaskInstance $instance): void
    {
        $now = $this->clock->now();
        $instance->status = TaskInstanceStatus::Completed;
        $instance->submitted_at = $instance->submitted_at ?? $now;
        $instance->completed_at = $now;
        $instance->save();

        $snapshot = is_array($instance->snapshot) ? $instance->snapshot : [];
        $this->rewards->grant(new RewardIntent(
            familyId: $instance->family_id,
            childId: $instance->child_id,
            policyCode: 'task.complete',
            grantKey: 'task:instance:'.$instance->id.':complete',
            sourceModule: SourceModule::Task,
            sourceType: 'task_instance',
            sourceId: $instance->id,
            occurredAt: $now,
            xpAmount: (int) ($snapshot['xp_reward'] ?? 0),
            coinAmount: (int) ($snapshot['coin_reward'] ?? 0),
            postImmediately: true,
        ));
    }

    private function cancelPendingApproval(TaskInstance $instance, ?int $actorUserId): void
    {
        $pending = ApprovalRequest::query()
            ->where('type', ApprovalType::TaskCompletion)
            ->where('subject_type', 'task_instance')
            ->where('subject_id', $instance->id)
            ->where('status', ApprovalStatus::Pending)
            ->lockForUpdate()
            ->first();
        if ($pending !== null) {
            $this->approvals->cancel($pending, $actorUserId);
        }
    }

    private function hasApprovedCompletion(TaskInstance $instance): bool
    {
        return ApprovalRequest::query()
            ->where('type', ApprovalType::TaskCompletion)
            ->where('subject_type', 'task_instance')
            ->where('subject_id', $instance->id)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    private function reverseOpenGrants(TaskInstance $instance, int $actorUserId): void
    {
        $grants = RewardGrant::query()
            ->where('source_type', 'task_instance')
            ->where('source_id', $instance->id)
            ->where('policy_code', 'task.complete')
            ->whereIn('status', [RewardGrantStatus::Granted, RewardGrantStatus::Capped])
            ->lockForUpdate()
            ->get();

        foreach ($grants as $grant) {
            $this->rewards->reversePosted($grant, $actorUserId);
        }
    }

    private function assertChildInstance(TaskInstance $instance, ChildProfile $child): void
    {
        if ($instance->child_id !== $child->id || $instance->family_id !== $child->family_id) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
    }
}
