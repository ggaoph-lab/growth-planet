<?php

namespace App\Modules\Approval\Services;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalEvent;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use Illuminate\Database\QueryException;

class ApprovalService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ApprovalHandlerRegistry $registry,
    ) {}

    public function createPending(
        int $familyId,
        int $childId,
        ApprovalType $type,
        string $subjectType,
        int $subjectId,
        ?int $requestedBy,
    ): ApprovalRequest {
        $activeKey = $type->value.':'.$subjectType.':'.$subjectId;
        $existing = ApprovalRequest::query()
            ->where('active_key', $activeKey)
            ->where('status', ApprovalStatus::Pending)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $request = ApprovalRequest::query()->create([
                'family_id' => $familyId,
                'child_id' => $childId,
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => ApprovalStatus::Pending,
                'requested_by' => $requestedBy,
                'requested_at' => $this->clock->now(),
                'active_key' => $activeKey,
                'lock_version' => 0,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return ApprovalRequest::query()->where('active_key', $activeKey)->where('status', ApprovalStatus::Pending)->firstOrFail();
            }
            throw $e;
        }

        $this->recordEvent($request, null, ApprovalStatus::Pending, $requestedBy, null);

        return $request;
    }

    public function decide(
        ApprovalRequest $request,
        ApprovalStatus $decision,
        int $expectedLockVersion,
        int $actorUserId,
        ?string $note = null,
    ): ApprovalRequest {
        if (! in_array($decision, [ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Returned], true)) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('无效的审核决定', 'Invalid approval decision'), 422);
        }

        if ($request->status !== ApprovalStatus::Pending) {
            throw new ApiException('ALREADY_DECIDED', BiMsg::of('该申请已处理', 'This request has already been processed'), 409);
        }

        if ($request->lock_version !== $expectedLockVersion) {
            throw new ApiException('VERSION_CONFLICT', BiMsg::of('数据已被更新，请刷新后重试', 'Data was updated, please refresh and try again'), 409);
        }

        $from = $request->status;
        $updated = ApprovalRequest::query()
            ->whereKey($request->id)
            ->where('status', ApprovalStatus::Pending)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'status' => $decision,
                'decided_by' => $actorUserId,
                'decided_at' => $this->clock->now(),
                'decision_note' => $note,
                'active_key' => null,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => $this->clock->now(),
            ]);

        if ($updated === 0) {
            throw new ApiException('VERSION_CONFLICT', BiMsg::of('数据已被更新，请刷新后重试', 'Data was updated, please refresh and try again'), 409);
        }

        $request->refresh();
        $this->recordEvent($request, $from, $decision, $actorUserId, $note);
        $this->registry->get($request->type->value)->onDecision($request, $decision, $note, $actorUserId);

        return $request;
    }

    public function cancel(ApprovalRequest $request, ?int $actorUserId, ?string $note = null): ApprovalRequest
    {
        if ($request->status !== ApprovalStatus::Pending) {
            throw new ApiException('ALREADY_DECIDED', BiMsg::of('该申请已处理', 'This request has already been processed'), 409);
        }

        $from = $request->status;
        $request->status = ApprovalStatus::Cancelled;
        $request->decided_by = $actorUserId;
        $request->decided_at = $this->clock->now();
        $request->decision_note = $note;
        $request->active_key = null;
        $request->lock_version++;
        $request->save();

        $this->recordEvent($request, $from, ApprovalStatus::Cancelled, $actorUserId, $note);

        return $request;
    }

    private function recordEvent(ApprovalRequest $request, ?ApprovalStatus $from, ApprovalStatus $to, ?int $actorId, ?string $note): void
    {
        ApprovalEvent::query()->create([
            'approval_request_id' => $request->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => $this->clock->now(),
        ]);
    }
}
