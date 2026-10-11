<?php

namespace App\Modules\English\Services;

use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\English\Models\EnglishShow;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Support\Facades\DB;

class EnglishLogService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLog(ChildProfile $child, array $data): EnglishWatchLog
    {
        $tz = $child->family->timezone;
        $today = $this->clock->today($tz);
        $yesterday = $today->subDay()->toDateString();
        $todayStr = $today->toDateString();
        $watchDate = $data['watch_date'];

        if ($watchDate !== $todayStr && $watchDate !== $yesterday) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('只能登记今天或昨天的观看', 'You can only log viewing for today or yesterday'), 422);
        }

        EnglishShow::query()
            ->where('family_id', $child->family_id)
            ->where('active', true)
            ->findOrFail($data['show_id']);

        $pendingCount = EnglishWatchLog::query()
            ->where('child_id', $child->id)
            ->where('watch_date', $watchDate)
            ->where('status', 'PENDING')
            ->count();
        if ($pendingCount >= 3) {
            throw new ApiException('LIMIT_REACHED', BiMsg::of('同一天待确认的登记已达上限', 'Pending logs for this day have reached the limit'), 422);
        }

        return DB::transaction(function () use ($child, $data, $watchDate) {
            $log = EnglishWatchLog::query()->create([
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'show_id' => $data['show_id'],
                'watch_date' => $watchDate,
                'minutes' => $data['minutes'],
                'self_chosen' => $data['self_chosen'] ?? false,
                'liking' => $data['liking'] ?? null,
                'words_learned' => $data['words_learned'] ?? null,
                'status' => 'PENDING',
                'created_at' => $this->clock->now(),
            ]);

            $approval = $this->approvals->createPending(
                $child->family_id,
                $child->id,
                ApprovalType::EnglishLog,
                'english_watch_log',
                $log->id,
                $child->member->user_id,
            );

            $log->approval_request_id = $approval->id;
            $log->save();

            return $log->fresh(['show']);
        });
    }

    public function cancelLog(ChildProfile $child, int $logId): void
    {
        $log = EnglishWatchLog::query()
            ->where('child_id', $child->id)
            ->whereKey($logId)
            ->firstOrFail();

        if ($log->status !== 'PENDING') {
            throw new ApiException('INVALID_STATE', BiMsg::of('只能撤回待确认的登记', 'Only pending logs can be withdrawn'), 409);
        }

        $log->status = 'CANCELLED';
        $log->save();
    }
}
