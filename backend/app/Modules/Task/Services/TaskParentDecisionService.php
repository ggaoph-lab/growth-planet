<?php

namespace App\Modules\Task\Services;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Models\TaskInstance;
use Illuminate\Support\Facades\DB;

class TaskParentDecisionService
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ApprovalService $approvals,
    ) {}

    public function approve(TaskInstance $instance, int $lockVersion): TaskInstance
    {
        return $this->decide($instance, ApprovalStatus::Approved, $lockVersion, null);
    }

    public function return(TaskInstance $instance, int $lockVersion, ?string $note): TaskInstance
    {
        return $this->decide($instance, ApprovalStatus::Returned, $lockVersion, $note);
    }

    private function decide(TaskInstance $instance, ApprovalStatus $status, int $lockVersion, ?string $note): TaskInstance
    {
        if ($instance->family_id !== $this->context->familyId()) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }

        $approval = ApprovalRequest::query()
            ->where('subject_type', 'task_instance')
            ->where('subject_id', $instance->id)
            ->where('status', ApprovalStatus::Pending)
            ->firstOrFail();

        DB::transaction(function () use ($approval, $status, $lockVersion, $note) {
            $this->approvals->decide($approval, $status, $lockVersion, $this->context->user()->id, $note);
        });

        return $instance->fresh();
    }
}
