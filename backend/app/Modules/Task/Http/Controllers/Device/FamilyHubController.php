<?php

namespace App\Modules\Task\Http\Controllers\Device;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Shop\Models\RewardRedemption;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Services\TaskKidFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyHubController
{
    public function __construct(private readonly DeviceTokens $tokens, private readonly ApprovalService $approvals, private readonly TaskKidFlowService $tasks) {}

    private function device(Request $request): TrustedDevice
    {
        $device = $this->tokens->fromRequest($request);
        abort_if($device === null, 401);

        return $device;
    }

    private function actor(Request $request, TrustedDevice $device): int
    {
        abort_unless($device->hub_approvals_enabled, 403, '此设备尚未授权审核');
        $parent = $device->authorizer;
        abort_unless($parent?->isParent() && $parent->member->family_id === $device->family_id
            && $parent->member->status->value === 'active', 403);

        return (int) $parent->id;
    }

    public function complete(Request $request, int $id)
    {
        $device = $this->device($request);
        $instance = TaskInstance::query()->where('family_id', $device->family_id)->findOrFail($id);
        $child = ChildProfile::query()->where('family_id', $device->family_id)->findOrFail($instance->child_id);
        if ($request->boolean('self_checked')) {
            $this->tasks->selfCheck($instance, $child);
        }

        return response()->json(['data' => $this->tasks->submit($instance, $child, 'ThinkSmart View 完成')]);
    }

    public function pending(Request $request)
    {
        $device = $this->device($request);
        $this->tasks->retireTaskApprovals($device->family_id);
        $items = ApprovalRequest::query()->where('family_id', $device->family_id)->where('status', ApprovalStatus::Pending)->latest('id')->limit(100)->get();
        $children = ChildProfile::query()->where('family_id', $device->family_id)->pluck('nickname', 'id');
        $redemptions = RewardRedemption::query()->where('family_id', $device->family_id)->whereIn('id', $items->where('subject_type', 'reward_redemption')->pluck('subject_id'))->get()->keyBy('id');

        return response()->json(['data' => ['can_approve' => (bool) $device->hub_approvals_enabled, 'items' => $items->map(function ($item) use ($children, $redemptions) {
            $redemption = $item->subject_type === 'reward_redemption' ? $redemptions->get($item->subject_id) : null;
            $snapshot = $redemption?->reward_snapshot ?? [];

            return ['id' => $item->id, 'child_id' => $item->child_id, 'child' => $children[$item->child_id] ?? '', 'type' => $item->type->value,
                'title' => $snapshot['name'] ?? match ($item->type->value) {
                    'english_log' => '英语观看记录', 'reward_grant' => '成长奖励申请', default => '待审核申请'
                },
                'minutes' => $this->minutes($snapshot), 'lock_version' => $item->lock_version, 'requested_at' => $item->requested_at?->toIso8601String()];
        })]]);
    }

    private function minutes(array $snapshot): int
    {
        return ($snapshot['kind'] ?? '') === 'screen_time' ? max(0, (int) ($snapshot['game_minutes'] ?? 0)) : 0;
    }

    public function decide(Request $request)
    {
        $device = $this->device($request);
        $actor = $this->actor($request, $device);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.lock_version' => ['required', 'integer', 'min:0'],
            'decision' => ['required', 'in:APPROVED,REJECTED']]);

        return DB::transaction(function () use ($device, $actor, $data) {
            // Serialize one hub's batches, including retries and active game extensions.
            TrustedDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('family_hub_game_sessions')->where('device_id', $device->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                return response()->json(['data' => ['game' => $this->gameData($existing), 'replayed' => true]]);
            }
            $selected = collect($data['items'])->keyBy('id');
            $items = ApprovalRequest::query()->where('family_id', $device->family_id)->whereIn('id', $selected->keys())->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === $selected->count(), 404);
            $minutes = 0;
            $gameChild = null;
            foreach ($items as $item) {
                if ($data['decision'] === 'APPROVED' && $item->subject_type === 'reward_redemption') {
                    $redemption = RewardRedemption::query()->where('family_id', $device->family_id)->findOrFail($item->subject_id);
                    $duration = $this->minutes($redemption->reward_snapshot);
                    abort_if(($redemption->reward_snapshot['kind'] ?? '') === 'screen_time' && $duration < 1, 422, '游戏兑换缺少分钟数，请先在成长星球设置');
                    if ($duration > 0) {
                        abort_if($gameChild !== null && $gameChild !== $item->child_id, 422, '请分别审核不同孩子的游戏时间');
                        $gameChild = $item->child_id;
                        $minutes += $duration;
                    }
                }
                $this->approvals->decide($item, ApprovalStatus::from($data['decision']), (int) $selected[$item->id]['lock_version'], $actor, 'ThinkSmart View 批量审核');
            }
            $game = null;
            if ($minutes > 0) {
                $active = DB::table('family_hub_game_sessions')->where('device_id', $device->id)->where('ends_at', '>', now())->latest('ends_at')->first();
                abort_if($active && $active->child_id !== $gameChild, 422, '请等待当前孩子的游戏计时结束');
                $end = $active ? CarbonImmutable::parse($active->ends_at)->addMinutes($minutes) : now()->addMinutes($minutes);
                $id = DB::table('family_hub_game_sessions')->insertGetId(['family_id' => $device->family_id, 'device_id' => $device->id,
                    'child_id' => $gameChild, 'request_key' => $data['request_key'], 'approval_ids' => json_encode($items->pluck('id')->all()),
                    'minutes' => $minutes, 'starts_at' => now(), 'ends_at' => $end, 'created_at' => now(), 'updated_at' => now()]);
                $game = DB::table('family_hub_game_sessions')->find($id);
            }

            return response()->json(['data' => ['approved_count' => $items->count(), 'game' => $this->gameData($game)]]);
        });
    }

    private function gameData(?object $game): ?array
    {
        if (! $game) {
            return null;
        }

        return ['id' => $game->id, 'minutes' => $game->minutes, 'child_id' => $game->child_id,
            'ends_at_ms' => CarbonImmutable::parse($game->ends_at, 'UTC')->getTimestampMs(),
            'server_time_ms' => now()->getTimestampMs()];
    }

    public function game(Request $request)
    {
        $device = $this->device($request);
        $game = DB::table('family_hub_game_sessions')->where('device_id', $device->id)->latest('id')->first();

        return response()->json(['data' => ['game' => $this->gameData($game), 'server_time' => now()->toIso8601String()]]);
    }
}
