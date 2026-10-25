<?php

namespace Tests\Feature\Growth;

use App\Modules\Game\Services\SchulteScoreService;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Services\DailyPointsService;
use App\Modules\Ledger\Services\LevelCurve;
use App\Modules\Ledger\Services\XpLedgerService;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Services\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_habitica_curve_and_multi_level_up_with_overflow(): void
    {
        $curve = new LevelCurve;
        $this->assertSame(25, $curve->toNextLevel(1));
        $this->assertSame(50, $curve->toNextLevel(2));
        $this->assertSame(150, $curve->toNextLevel(5));

        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $ledger = app(XpLedgerService::class);

        // 25 + 50 + 10 overflow → level 3 with 10 XP remaining
        $entry = $ledger->credit($xp, 85, LedgerEntryType::Reward, SourceModule::Parent, 'test', 1, 'xp:level', '2026-10-22');
        $xp = $xp->fresh();
        $this->assertSame(3, $xp->level);
        $this->assertSame(10, $xp->balance);
        $this->assertSame(85, $xp->lifetime_earned);

        // Reversing after level-ups must level down instead of failing with insufficient XP.
        $ledger->reverse($entry, 'xp:level:rev');
        $xp = $xp->fresh();
        $this->assertSame(1, $xp->level);
        $this->assertSame(0, $xp->balance);
        $this->assertSame(85, $xp->lifetime_earned);
    }

    public function test_daily_points_are_idempotent_per_business_day(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(DailyPointsService::class);

        $this->assertSame('granted', $service->grantForChild($child));
        $this->assertSame('skipped', $service->grantForChild($child));

        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        $this->assertSame(10, $points->balance);
        $this->assertSame(10, $points->lifetime_earned);
    }

    public function test_virtual_reward_redeems_points_not_coins(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        PointAccount::query()->where('child_id', $child->id)->update([
            'balance' => 20,
            'lifetime_earned' => 20,
            'updated_at' => now(),
        ]);

        $reward = Reward::query()->create([
            'family_id' => $family->id,
            'name' => '游戏时间 30 分钟',
            'kind' => RewardKind::ScreenTime,
            'coin_price' => 0,
            'point_price' => 5,
            'stock' => null,
            'requires_approval' => false,
            'status' => RewardStatus::OnShelf,
            'version' => 1,
        ]);

        app(RedemptionService::class)->request($reward, $child, $family, 'test:point:redeem:1');

        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        $this->assertSame(15, $points->balance);
        $this->assertSame(0, $points->held);
        $this->assertSame(0, (int) CoinAccount::query()->where('child_id', $child->id)->value('balance'));

        $wallet = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/wallet')
            ->assertOk()
            ->json('data');
        $this->assertSame(15, $wallet['points']['available']);
        $this->assertArrayHasKey('level', $wallet['xp']);
        $this->assertArrayHasKey('to_next', $wallet['xp']);
    }

    public function test_game_rewards_credit_points_not_coins(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        $result = app(SchulteScoreService::class)->record($child, $family, 3, 8_000);
        $this->assertSame(1, $result['awarded_coins']);
        $this->assertSame(1, (int) PointAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(0, (int) CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_physical_reward_still_uses_coins(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        CoinAccount::query()->where('child_id', $child->id)->update([
            'balance' => 30,
            'lifetime_earned' => 30,
            'updated_at' => now(),
        ]);

        $reward = Reward::query()->create([
            'family_id' => $family->id,
            'name' => '冰激凌',
            'kind' => RewardKind::Physical,
            'coin_price' => 8,
            'point_price' => null,
            'stock' => null,
            'requires_approval' => false,
            'status' => RewardStatus::OnShelf,
            'version' => 1,
        ]);

        app(RedemptionService::class)->request($reward, $child, $family, 'test:coin:redeem:1');

        $this->assertSame(22, (int) CoinAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(0, (int) PointAccount::query()->where('child_id', $child->id)->value('balance'));
    }
}
