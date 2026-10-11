<?php

namespace Tests\Feature\Growth;

use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Services\WishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WishTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_can_add_and_list_a_wish(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->loginKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/wishes', [
                'name' => '乐高',
                'description' => '想拼一盒城市系列',
                'coin_price' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '乐高')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.coin_price', 40)
            ->assertJsonPath('data.child_id', $child->id);

        $this->getJson('/api/v1/kid/wishes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '乐高');
    }

    public function test_another_child_cannot_see_the_wish(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $other = $this->makeChild($family, ['nickname' => '小月亮']);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(WishService::class)->create($child, '乐高', null, 40);

        $this->actingAsKid($other, $device, $token)
            ->getJson('/api/v1/kid/wishes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_parent_converts_wish_into_on_shelf_reward(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $wish = app(WishService::class)->create($child, '乐高', null, 40);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/wishes')
            ->assertOk()
            ->assertJsonPath('data.0.child.nickname', '小星星')
            ->assertJsonPath('data.0.name', '乐高');

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/wishes/{$wish->id}/convert")
            ->assertOk()
            ->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.reward.name', '乐高')
            ->assertJsonPath('data.reward.coin_price', 40);

        $reward = Reward::query()->where('family_id', $family->id)->where('name', '乐高')->firstOrFail();
        $this->assertSame(RewardStatus::OnShelf, $reward->status);

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/wishes/{$wish->id}/convert")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WISH_NOT_PENDING');
    }

    public function test_parent_can_reject_a_wish(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $wish = app(WishService::class)->create($child, '深夜动画', null, null);

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/wishes/{$wish->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_kid_cannot_open_parent_wishes(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->loginKid($child, $device, $token)
            ->getJson('/api/v1/parent/wishes')
            ->assertForbidden();
    }
}
