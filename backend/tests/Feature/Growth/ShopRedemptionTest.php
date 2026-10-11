<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Shop\Enums\RedemptionStatus;
use App\Modules\Shop\Services\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_redeem_places_coin_hold(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(CoinLedgerService::class)->credit(
            $coin,
            50,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'seed',
            1,
            'seed:coins',
            '2026-10-11',
        );

        $reward = $this->seedReward($family, ['coin_price' => 20]);

        $this->loginKid($child->fresh(['member.user']), $device, $token);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/redemptions', ['reward_id' => $reward->id])
            ->assertCreated();

        $coin = $coin->fresh();
        $this->assertSame(50, $coin->balance);
        $this->assertSame(20, $coin->held);
        $this->assertSame(30, $coin->available());
    }

    public function test_parent_approve_redemption_captures_hold(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(CoinLedgerService::class)->credit(
            $coin,
            50,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'seed',
            1,
            'seed:coins:2',
            '2026-10-11',
        );

        $reward = $this->seedReward($family, ['coin_price' => 20]);
        $redemption = app(RedemptionService::class)->request(
            $reward,
            $child,
            $family,
            'test:redemption:1',
        );

        $approval = ApprovalRequest::query()->whereKey($redemption->approval_request_id)->firstOrFail();

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/approvals/{$approval->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $coin = $coin->fresh();
        $this->assertSame(30, $coin->balance);
        $this->assertSame(0, $coin->held);
        $this->assertSame(RedemptionStatus::Approved, $redemption->fresh()->status);
    }
}
