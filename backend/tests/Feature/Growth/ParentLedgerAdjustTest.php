<?php

namespace Tests\Feature\Growth;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Services\CoinLedgerService;
use App\Modules\Ledger\Services\PointLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentLedgerAdjustTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_deduct_points_and_coins_and_see_ledger(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $date = '2026-10-27';

        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        $coins = CoinAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(PointLedgerService::class)->credit(
            $points,
            20,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'test',
            1,
            'seed:point',
            $date,
        );
        app(CoinLedgerService::class)->credit(
            $coins,
            15,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'test',
            1,
            'seed:coin',
            $date,
        );

        $this->actingAsParent($parent, sudo: true)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/children/{$child->id}/deduct", [
                'point' => 5,
                'coin' => 3,
                'note' => '晚睡扣分',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ok', true);

        $this->assertSame(15, PointAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(12, CoinAccount::query()->where('child_id', $child->id)->value('balance'));

        $pointLedger = $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/children/{$child->id}/ledger?asset=point")
            ->assertOk()
            ->json('data');
        $this->assertSame(15, $pointLedger['available']);
        $this->assertSame(LedgerEntryType::ParentDeduction->value, $pointLedger['entries'][0]['entry_type']);
        $this->assertSame(-5, $pointLedger['entries'][0]['amount']);
        $this->assertSame('晚睡扣分', $pointLedger['entries'][0]['note']);

        $coinLedger = $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/children/{$child->id}/ledger?asset=coin")
            ->assertOk()
            ->json('data');
        $this->assertSame(-3, $coinLedger['entries'][0]['amount']);
        $this->assertSame(LedgerEntryType::ParentDeduction->value, $coinLedger['entries'][0]['entry_type']);
    }

    public function test_deduct_requires_note_and_rejects_overdraft(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent, sudo: true)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/children/{$child->id}/deduct", [
                'point' => 1,
            ])
            ->assertStatus(422);

        $this->actingAsParent($parent, sudo: true)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/children/{$child->id}/deduct", [
                'point' => 1,
                'note' => '不够扣',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_POINTS');
    }

    public function test_kid_can_view_point_and_coin_ledger(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $date = '2026-10-27';

        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(PointLedgerService::class)->credit(
            $points,
            10,
            LedgerEntryType::DailyAllowance,
            SourceModule::System,
            'daily',
            null,
            'seed:daily',
            $date,
        );
        app(PointLedgerService::class)->debit(
            $points->fresh(),
            2,
            LedgerEntryType::ParentDeduction,
            SourceModule::Parent,
            'child_profile',
            $child->id,
            'seed:deduct',
            $date,
            $parent->id,
            $parent->id,
            '忘记收拾书桌',
        );

        $ledger = $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/wallet/ledger?asset=point')
            ->assertOk()
            ->json('data');

        $this->assertSame('point', $ledger['asset']);
        $this->assertSame(8, $ledger['available']);
        $this->assertSame(-2, $ledger['entries'][0]['amount']);
        $this->assertSame(LedgerEntryType::ParentDeduction->value, $ledger['entries'][0]['entry_type']);
        $this->assertSame('忘记收拾书桌', $ledger['entries'][0]['note']);
        $this->assertSame('child_profile', $ledger['entries'][0]['source_type']);
        $this->assertSame(LedgerEntryType::DailyAllowance->value, $ledger['entries'][1]['entry_type']);
        $this->assertSame('daily', $ledger['entries'][1]['source_type']);
        $this->assertArrayNotHasKey('idempotency_key', $ledger['entries'][0]);
    }
}
