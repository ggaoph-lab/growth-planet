<?php

namespace App\Modules\English\Services;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Core\Support\Clock;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\ScreenTime\Services\ScreenTimeService;

class EnglishLogApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
        private readonly ScreenTimeService $screenTime,
    ) {}

    public function type(): string
    {
        return ApprovalType::EnglishLog->value;
    }

    public function onDecision(ApprovalRequest $request, ApprovalStatus $decision, ?string $note, int $actorUserId): void
    {
        $log = EnglishWatchLog::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $child = ChildProfile::query()->findOrFail($log->child_id);

        if ($decision === ApprovalStatus::Approved) {
            $minutes = $log->approved_minutes ?? $log->minutes;
            $log->status = 'APPROVED';
            $log->reviewed_by = $actorUserId;
            $log->reviewed_at = $this->clock->now();
            $log->parent_note = $note;
            $log->approved_minutes = $minutes;
            $log->save();

            $this->rewards->grant(new RewardIntent(
                familyId: $child->family_id,
                childId: $child->id,
                policyCode: 'english.log',
                grantKey: 'english:log:'.$log->id,
                sourceModule: SourceModule::English,
                sourceType: 'english_watch_log',
                sourceId: $log->id,
                occurredAt: $this->clock->now(),
                approvedBy: $actorUserId,
            ));

            if ($log->self_chosen) {
                $this->rewards->grant(new RewardIntent(
                    familyId: $child->family_id,
                    childId: $child->id,
                    policyCode: 'english.active_choice',
                    grantKey: 'english:choice:'.$log->id,
                    sourceModule: SourceModule::English,
                    sourceType: 'english_watch_log',
                    sourceId: $log->id,
                    occurredAt: $this->clock->now(),
                    approvedBy: $actorUserId,
                ));
            }

            $this->screenTime->recordCounted(
                $child,
                'english',
                'english_watch_log',
                $log->id,
                $minutes * 60,
                $log->watch_date->toDateString(),
            );

            return;
        }

        if ($decision === ApprovalStatus::Rejected) {
            $log->status = 'REJECTED';
            $log->reviewed_by = $actorUserId;
            $log->reviewed_at = $this->clock->now();
            $log->parent_note = $note;
            $log->save();
        }
    }
}
