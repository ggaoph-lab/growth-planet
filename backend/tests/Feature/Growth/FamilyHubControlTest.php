<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Services\RedemptionService;
use App\Modules\Task\Services\TaskInstanceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FamilyHubControlTest extends TestCase
{
    use RefreshDatabase;

    private function setupHub(): array
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $device->hub_approvals_enabled = true;
        $device->save();
        $account = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(CoinLedgerService::class)->credit($account, 100, LedgerEntryType::Reward, SourceModule::Parent, 'seed', 1, 'seed:hub', now()->toDateString());
        $reward = $this->seedReward($family, ['kind' => RewardKind::ScreenTime, 'coin_price' => 10, 'game_minutes' => 15]);
        $items = [];
        foreach ([1, 2] as $i) {
            $redemption = app(RedemptionService::class)->request($reward, $child, $family, 'hub:redeem:'.$i);
            $approval = ApprovalRequest::findOrFail($redemption->approval_request_id);
            $items[] = ['id' => $approval->id, 'lock_version' => $approval->lock_version];
        }

        return compact('family', 'parent', 'child', 'device', 'token', 'items', 'account');
    }

    public function test_batch_game_approval_adds_minutes_and_retry_does_not_charge_twice(): void
    {
        extract($this->setupHub());
        $body = ['request_key' => (string) Str::uuid(), 'items' => $items, 'decision' => 'APPROVED'];
        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-hub/approvals')->assertOk()->assertJsonPath('data.items.0.minutes', 15);
        $result = $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', $body)
            ->assertOk()->assertJsonPath('data.game.minutes', 30)->json('data.game');
        $this->assertEqualsWithDelta(30 * 60000, $result['ends_at_ms'] - $result['server_time_ms'], 1500);
        $this->assertSame(80, $account->fresh()->balance);
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(80, $account->fresh()->balance);
        $this->assertDatabaseCount('family_hub_game_sessions', 1);
    }

    public function test_unprivileged_device_cannot_approve_and_cross_family_batch_is_atomic(): void
    {
        extract($this->setupHub());
        [, $plainToken] = $this->makeDevice($family, $parent);
        $body = ['request_key' => (string) Str::uuid(), 'items' => $items, 'decision' => 'APPROVED'];
        $this->withCookie('gp_device', $plainToken)->postJson('/api/v1/device/family-hub/decide', $body)->assertForbidden();
        $body['items'][] = ['id' => 99999999, 'lock_version' => 0];
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', $body)->assertNotFound();
        $this->assertSame(100, $account->fresh()->balance);
        $this->assertDatabaseCount('family_hub_game_sessions', 0);
    }

    public function test_stale_approval_rolls_back_entire_batch(): void
    {
        extract($this->setupHub());
        $items[1]['lock_version'] = 50;
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', [
            'request_key' => (string) Str::uuid(), 'items' => $items, 'decision' => 'APPROVED',
        ])->assertConflict();
        $this->assertSame(100, $account->fresh()->balance);
        $this->assertDatabaseCount('family_hub_game_sessions', 0);
    }

    public function test_hub_completes_family_task_once_and_cannot_complete_other_family_task(): void
    {
        extract($this->setupHub());
        $this->seedTask($family, $parent);
        $task = app(TaskInstanceGenerator::class)->ensureForChildOnDate($family, $child, now('Asia/Shanghai')->toDateString())->first();
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/tasks/'.$task->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/tasks/'.$task->id.'/complete')->assertOk();
        ['family' => $other, 'parent' => $otherParent] = $this->makeFamily('other-parent');
        [, $otherToken] = $this->makeDevice($other, $otherParent);
        $this->withCookie('gp_device', $otherToken)->postJson('/api/v1/device/family-hub/tasks/'.$task->id.'/complete')->assertNotFound();
    }

    public function test_new_batch_extends_active_game_and_rejection_never_starts_game(): void
    {
        extract($this->setupHub());
        $first = $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', [
            'request_key' => (string) Str::uuid(), 'items' => [$items[0]], 'decision' => 'APPROVED',
        ])->assertOk()->json('data.game');
        $second = $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', [
            'request_key' => (string) Str::uuid(), 'items' => [$items[1]], 'decision' => 'APPROVED',
        ])->assertOk()->json('data.game');
        $this->assertSame(15 * 60000, $second['ends_at_ms'] - $first['ends_at_ms']);
        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-hub/game')->assertOk()->assertJsonPath('data.game.id', $second['id']);
    }

    public function test_rejection_releases_holds_without_game_session(): void
    {
        extract($this->setupHub());
        $this->withCookie('gp_device', $token)->postJson('/api/v1/device/family-hub/decide', [
            'request_key' => (string) Str::uuid(), 'items' => $items, 'decision' => 'REJECTED',
        ])->assertOk()->assertJsonPath('data.game', null);
        $this->assertSame(100, $account->fresh()->balance);
        $this->assertSame(0, $account->fresh()->held);
        $this->assertDatabaseCount('family_hub_game_sessions', 0);
    }
}
