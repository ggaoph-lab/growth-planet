<?php

namespace App\Modules\Approval\Http\Controllers\Parent;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalEvent;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Models\RewardRedemption;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Services\TaskKidFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ApprovalService $approvals,
        private readonly TaskKidFlowService $tasks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->tasks->retireTaskApprovals($this->context->familyId());

        $status = $request->query('status', 'PENDING');
        $query = ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json(['data' => $this->presentMany($query->limit(100)->get())]);
    }

    public function show(int $id): JsonResponse
    {
        $item = ApprovalRequest::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        return response()->json(['data' => $this->presentMany(collect([$item]))->first()]);
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

        if ($approval->type === ApprovalType::TaskCompletion) {
            $this->tasks->retireTaskApprovals($approval->family_id, $approval->child_id);

            return response()->json(['data' => $approval->fresh()]);
        }

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

    /**
     * @param  Collection<int, ApprovalRequest>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function presentMany(Collection $items): Collection
    {
        $familyId = $this->context->familyId();
        $childNames = ChildProfile::query()
            ->where('family_id', $familyId)
            ->whereIn('id', $items->pluck('child_id')->unique()->all())
            ->pluck('nickname', 'id');

        $taskIds = $items
            ->filter(fn (ApprovalRequest $item) => $item->type === ApprovalType::TaskCompletion && $item->subject_type === 'task_instance')
            ->pluck('subject_id')
            ->unique()
            ->all();
        $tasks = $taskIds === []
            ? collect()
            : TaskInstance::query()
                ->where('family_id', $familyId)
                ->whereIn('id', $taskIds)
                ->get()
                ->keyBy('id');

        $redemptionIds = $items
            ->filter(fn (ApprovalRequest $item) => $item->type === ApprovalType::Redemption && $item->subject_type === 'reward_redemption')
            ->pluck('subject_id')
            ->unique()
            ->all();
        $redemptions = $redemptionIds === []
            ? collect()
            : RewardRedemption::query()
                ->where('family_id', $familyId)
                ->whereIn('id', $redemptionIds)
                ->get()
                ->keyBy('id');

        return $items->map(function (ApprovalRequest $item) use ($childNames, $tasks, $redemptions) {
            $data = $item->toArray();
            $data['child_name'] = $childNames[$item->child_id] ?? null;
            $data['summary'] = null;

            if ($item->type === ApprovalType::TaskCompletion) {
                $instance = $tasks->get($item->subject_id);
                $snapshot = is_array($instance?->snapshot) ? $instance->snapshot : [];
                $data['summary'] = [
                    'title' => $snapshot['title'] ?? null,
                    'group_title' => $snapshot['group_title'] ?? null,
                    'description' => $snapshot['description'] ?? null,
                    'xp_reward' => (int) ($snapshot['xp_reward'] ?? 0),
                    'coin_reward' => (int) ($snapshot['coin_reward'] ?? 0),
                ];
            }

            if ($item->type === ApprovalType::Redemption) {
                $redemption = $redemptions->get($item->subject_id);
                $snapshot = is_array($redemption?->reward_snapshot) ? $redemption->reward_snapshot : [];
                $data['summary'] = [
                    'title' => $snapshot['name'] ?? null,
                    'kind' => $snapshot['kind'] ?? null,
                    'coin_price' => (int) ($redemption?->coin_price ?? $snapshot['coin_price'] ?? 0),
                ];
            }

            return $data;
        })->values();
    }
}
