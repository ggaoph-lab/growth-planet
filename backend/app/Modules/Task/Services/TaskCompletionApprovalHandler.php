<?php

namespace App\Modules\Task\Services;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;

class TaskCompletionApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
    ) {}

    public function type(): string
    {
        return ApprovalType::TaskCompletion->value;
    }

    public function onDecision(ApprovalRequest $request, ApprovalStatus $decision, ?string $note, int $actorUserId): void
    {
        $instance = TaskInstance::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();

        if ($decision === ApprovalStatus::Approved) {
            $snapshot = $instance->snapshot;
            $grantKey = 'task:instance:'.$instance->id.':complete';
            $this->rewards->grant(new RewardIntent(
                familyId: $instance->family_id,
                childId: $instance->child_id,
                policyCode: 'task.complete',
                grantKey: $grantKey,
                sourceModule: SourceModule::Task,
                sourceType: 'task_instance',
                sourceId: $instance->id,
                occurredAt: $this->clock->now(),
                xpAmount: (int) ($snapshot['xp_reward'] ?? 0),
                coinAmount: (int) ($snapshot['coin_reward'] ?? 0),
                approvalRequestId: $request->id,
                approvedBy: $actorUserId,
                postImmediately: true,
            ));

            $instance->status = TaskInstanceStatus::Completed;
            $instance->completed_at = $this->clock->now();
            $instance->save();

            return;
        }

        if ($decision === ApprovalStatus::Returned) {
            $instance->status = TaskInstanceStatus::Returned;
            $instance->save();
            $attempt = (int) $instance->submissions()->max('attempt_no');
            $instance->submissions()->where('attempt_no', $attempt)->update(['returned_reason' => $note]);

            return;
        }

        if ($decision === ApprovalStatus::Rejected) {
            $instance->status = TaskInstanceStatus::Cancelled;
            $instance->save();
        }
    }
}
