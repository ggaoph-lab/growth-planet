<?php

namespace App\Modules\Approval\Http\Controllers\Parent;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Approval\Models\ApprovalEvent;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'PENDING');
        $query = ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function show(int $id): JsonResponse
    {
        $item = ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        return response()->json(['data' => $item]);
    }

    public function events(int $id): JsonResponse
    {
        ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        $events = ApprovalEvent::query()
            ->where('approval_request_id', $id)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $events]);
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        return $this->decide($id, ApprovalStatus::Approved, $request);
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        return $this->decide($id, ApprovalStatus::Rejected, $request);
    }

    public function returnRequest(int $id, Request $request): JsonResponse
    {
        return $this->decide($id, ApprovalStatus::Returned, $request);
    }

    private function decide(int $id, ApprovalStatus $status, Request $request): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'approved_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);

        $approval = ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        if ($approval->type === ApprovalType::EnglishLog && isset($data['approved_minutes'])) {
            EnglishWatchLog::query()
                ->whereKey($approval->subject_id)
                ->where('child_id', $approval->child_id)
                ->update(['approved_minutes' => $data['approved_minutes']]);
        }

        $result = DB::transaction(fn () => $this->approvals->decide(
            $approval,
            $status,
            (int) $data['lock_version'],
            $this->context->user()->id,
            $data['note'] ?? null,
        ));

        return response()->json(['data' => $result]);
    }
}
