<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Task\Services\TaskInstanceGenerator;
use App\Modules\Task\Services\TaskKidFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_paired_device_can_read_its_family_dashboard(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $this->makeChild($family);
        [, $token] = $this->makeDevice($family, $parent);

        $this->getJson('/api/v1/device/family-dashboard')->assertUnauthorized();
        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-dashboard')
            ->assertOk()->assertJsonStructure(['data' => ['date', 'tasks', 'activity']]);
    }

    public function test_dashboard_hides_reversed_rewards_after_repeated_completion_and_withdrawal(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, ['title' => '新东方阅读', 'xp_reward' => 3, 'coin_reward' => 1, 'point_reward' => 1]);
        $date = app(Clock::class)->today($family->timezone)->toDateString();
        $task = app(TaskInstanceGenerator::class)->ensureForChildOnDate($family, $child, $date)->first();
        $flow = app(TaskKidFlowService::class);
        for ($i = 0; $i < 3; $i++) {
            $flow->submit($task->fresh(), $child);
            $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-dashboard')
                ->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonCount(1, 'data.activity')
                ->assertJsonPath('data.activity.0.title', '完成任务 · 新东方阅读');
            if ($i < 2) {
                $flow->withdraw($task->fresh(), $child, $child->member->user_id);
                $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-dashboard')
                    ->assertOk()->assertJsonCount(0, 'data.activity');
            }
        }
        $this->assertSame(3, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(1, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(1, PointAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_parent_edits_weekly_multi_time_reminder_and_only_paired_family_device_sees_it(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        ['family' => $otherFamily, 'parent' => $otherParent] = $this->makeFamily('other-mama');
        [, $token] = $this->makeDevice($family, $parent);
        [, $otherToken] = $this->makeDevice($otherFamily, $otherParent);

        $id = $this->actingAsParent($parent)->postJson('/api/v1/parent/family-reminders', [
            'title' => '整理书包', 'weekdays' => [1, 3, 5], 'times' => ['07:30', '19:00'],
        ])->assertCreated()->assertJsonPath('data.times.1', '19:00')->json('data.id');

        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-reminders')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->withCookie('gp_device', $otherToken)->getJson('/api/v1/device/family-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsParent($parent)->putJson('/api/v1/parent/family-reminders/'.$id, [
            'title' => '整理书包', 'weekdays' => [2, 4], 'times' => ['07:20', '19:20'],
        ])->assertOk()->assertJsonPath('data.weekdays.0', 2);
        $this->actingAsParent($parent)->postJson('/api/v1/parent/family-reminders', [
            'title' => '重复时间', 'weekdays' => [1], 'times' => ['08:00', '08:00'],
        ])->assertUnprocessable();
    }
}
