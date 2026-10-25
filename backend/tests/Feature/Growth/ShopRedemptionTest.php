<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\PointLedgerService;
use App\Modules\Shop\Enums\RedemptionStatus;
use App\Modules\Shop\Enums\RewardKind;
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
            ->getJson('/api/v1/parent/approvals?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'redemption')
            ->assertJsonPath('data.0.summary.title', '奖励')
            ->assertJsonPath('data.0.summary.kind', 'physical')
            ->assertJsonPath('data.0.summary.coin_price', 20);

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/approvals/{$approval->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $coin = $coin->fresh();
        $this->assertSame(30, $coin->balance);
        $this->assertSame(0, $coin->held);
        $this->assertSame(RedemptionStatus::Fulfilled, $redemption->fresh()->status);
    }

    public function test_parent_approve_captures_hold_when_balance_exactly_matches_price(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $coin = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(CoinLedgerService::class)->credit(
            $coin,
            20,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'seed',
            1,
            'seed:coins:exact',
            '2026-10-11',
        );

        $reward = $this->seedReward($family, ['coin_price' => 20]);
        $redemption = app(RedemptionService::class)->request(
            $reward,
            $child,
            $family,
            'test:redemption:exact',
        );

        $this->assertSame(20, $coin->fresh()->held);
        $this->assertSame(0, $coin->fresh()->available());

        $approval = ApprovalRequest::query()->whereKey($redemption->approval_request_id)->firstOrFail();

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/approvals/{$approval->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $coin = $coin->fresh();
        $this->assertSame(0, $coin->balance);
        $this->assertSame(0, $coin->held);
        $this->assertSame(RedemptionStatus::Fulfilled, $redemption->fresh()->status);
    }

    public function test_parent_approve_captures_point_hold_when_balance_exactly_matches_price(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(PointLedgerService::class)->credit(
            $points,
            15,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'seed',
            1,
            'seed:points:exact',
            '2026-10-11',
        );

        $reward = $this->seedReward($family, [
            'kind' => RewardKind::ScreenTime,
            'coin_price' => 0,
            'point_price' => 15,
        ]);
        $redemption = app(RedemptionService::class)->request(
            $reward,
            $child,
            $family,
            'test:redemption:points:exact',
        );

        $this->assertSame(15, $points->fresh()->held);
        $this->assertSame(0, $points->fresh()->available());

        $approval = ApprovalRequest::query()->whereKey($redemption->approval_request_id)->firstOrFail();

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/approvals/{$approval->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $points = $points->fresh();
        $this->assertSame(0, $points->balance);
        $this->assertSame(0, $points->held);
        $this->assertSame(RedemptionStatus::Fulfilled, $redemption->fresh()->status);
    }

    public function test_kid_cancel_drops_the_pending_redemption_from_the_approval_center(): void
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
            'seed:coins:cancel',
            '2026-10-11',
        );

        $reward = $this->seedReward($family, ['coin_price' => 20]);
        $this->loginKid($child->fresh(['member.user']), $device, $token);

        $created = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/redemptions', ['reward_id' => $reward->id])
            ->assertCreated()
            ->json('data');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/redemptions/'.$created['ulid'].'/cancel')
            ->assertOk();

        $approval = ApprovalRequest::query()->findOrFail($created['approval_request_id']);
        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertSame(0, ApprovalRequest::query()
            ->where('family_id', $family->id)
            ->where('status', ApprovalStatus::Pending)
            ->count());
        $this->assertSame(50, $coin->fresh()->available());
    }
}
