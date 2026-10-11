<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoodRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_record_does_not_touch_ledger(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $food = $this->seedFood($family);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/food-records', [
                'food_id' => $food->id,
                'looked' => true,
                'smelled' => true,
                'touched' => true,
                'said_tasty' => false,
                'willing_again' => true,
            ])
            ->assertCreated();

        $this->assertSame(0, DB::table('coin_ledger')->count());
        $this->assertSame(0, DB::table('xp_ledger')->count());
        $this->assertSame(0, DB::table('reward_grants')->count());

        $wallet = $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/wallet')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $wallet['coin']['balance']);
        $this->assertSame(0, $wallet['xp']['balance']);
    }
}
