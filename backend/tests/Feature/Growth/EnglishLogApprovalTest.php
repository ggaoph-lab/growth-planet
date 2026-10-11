<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Core\Support\Clock;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\English\Services\EnglishLogService;
use App\Modules\ScreenTime\Models\ScreenTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnglishLogApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_log_approval_grants_rewards_and_screen_time(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->ensureMediaRewardPolicies($family);
        $show = $this->seedEnglishShow($family);

        $today = app(Clock::class)->today($family->timezone)->toDateString();

        $log = app(EnglishLogService::class)->createLog($child, [
            'show_id' => $show->id,
            'watch_date' => $today,
            'minutes' => 15,
            'self_chosen' => true,
            'liking' => 'like',
        ]);
        $logId = $log->id;

        $approval = ApprovalRequest::query()->where('subject_id', $logId)->firstOrFail();

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/parent/approvals/'.$approval->id.'/approve', [
                'lock_version' => $approval->lock_version,
            ])
            ->assertOk();

        $this->assertSame('APPROVED', EnglishWatchLog::query()->findOrFail($logId)->status);
        $this->assertSame(2, DB::table('reward_grants')->where('child_id', $child->id)->count());
        $this->assertSame(1, ScreenTimeEntry::query()->where('source_type', 'english_watch_log')->where('source_id', $logId)->count());
        $this->assertSame(900, ScreenTimeEntry::query()->where('source_id', $logId)->value('seconds'));
    }
}
