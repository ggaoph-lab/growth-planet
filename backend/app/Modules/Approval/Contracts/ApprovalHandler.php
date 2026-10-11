<?php

namespace App\Modules\Approval\Contracts;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Models\ApprovalRequest;

interface ApprovalHandler
{
    public function type(): string;

    public function onDecision(ApprovalRequest $request, ApprovalStatus $decision, ?string $note, int $actorUserId): void;
}
