<?php

namespace App\Modules\Shop\Services;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Models\Family;
use App\Modules\Shop\Models\RewardRedemption;

class RedemptionApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly RedemptionService $redemptions) {}

    public function type(): string
    {
        return ApprovalType::Redemption->value;
    }

    public function onDecision(ApprovalRequest $request, ApprovalStatus $decision, ?string $note, int $actorUserId): void
    {
        $redemption = RewardRedemption::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $family = Family::query()->findOrFail($request->family_id);

        if ($decision === ApprovalStatus::Approved) {
            $this->redemptions->fulfill($redemption, $family, $actorUserId);

            return;
        }

        if ($decision === ApprovalStatus::Rejected || $decision === ApprovalStatus::Returned) {
            $this->redemptions->finalizeRejected($redemption);
        }
    }
}
