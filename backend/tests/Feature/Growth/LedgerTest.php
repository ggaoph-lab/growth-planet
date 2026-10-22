<?php

namespace Tests\Feature\Growth;

use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Ledger\Services\XpLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_debit_hold_capture_and_available_balance(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $coins = app(CoinLedgerService::class);
        $xpLedger = app(XpLedgerService::class);
        $date = '2026-10-11';

        $xpLedger->credit($xp, 20, LedgerEntryType::Reward, SourceModule::Parent, 'test', 1, 'xp:1', $date);
        $coins->credit($coin->fresh(), 100, LedgerEntryType::Reward, SourceModule::Parent, 'test', 1, 'coin:1', $date);
        $coin = $coin->fresh();
        $this->assertSame(100, $coin->balance);
        $this->assertSame(100, $coin->available());

        $hold = $coins->hold($coin, 30, 'test', 1, 'hold:1');
        $coin = $coin->fresh();
        $this->assertSame(30, $coin->held);
        $this->assertSame(70, $coin->available());

        $coins->capture($hold, 'capture:1', $date);
        $coin = $coin->fresh();
        $this->assertSame(70, $coin->balance);
        $this->assertSame(0, $coin->held);
    }

    public function test_grant_key_prevents_double_pay(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'requires_approval' => false,
            'xp_reward' => 10,
            'coin_reward' => 5,
        ]);

        $instanceId = $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0.id');

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/ready")
            ->assertOk();
        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $wallet = $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/wallet')
            ->json('data');
        $this->assertSame(10, $wallet['xp']['balance']);
        $this->assertSame(1, $wallet['xp']['level']);
        $this->assertSame(5, $wallet['coin']['balance']);
        $this->assertSame(0, $wallet['points']['balance']);

        app(RewardGrantService::class)->grant(new RewardIntent(
            familyId: $family->id,
            childId: $child->id,
            policyCode: 'task.complete',
            grantKey: 'task:instance:'.$instanceId.':complete',
            sourceModule: SourceModule::Task,
            sourceType: 'task_instance',
            sourceId: $instanceId,
            occurredAt: now()->toImmutable(),
            xpAmount: 10,
            coinAmount: 5,
            postImmediately: true,
        ));

        $this->assertSame(10, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(5, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }
}
