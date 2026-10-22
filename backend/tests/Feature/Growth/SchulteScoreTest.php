<?php

namespace Tests\Feature\Growth;

use App\Modules\Game\Services\SchulteScoreService;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\TrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SchulteScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fast_grid_pays_once_per_size_per_day(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/games/schulte')
            ->assertOk()
            ->assertJsonPath('data.sizes.0.best_ms', null)
            ->assertJsonPath('data.sizes.0.coins', 1)
            ->assertJsonPath('data.sizes.1.coins', 2)
            ->assertJsonPath('data.sizes.2.coins', 3);

        $this->finish($child, $device, $token, 3, 8000)
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 1)
            ->assertJsonPath('data.improved', true)
            ->assertJsonPath('data.board.sizes.0.best_ms', 8000)
            ->assertJsonPath('data.board.sizes.0.ranks.0.coins', 1);

        $this->assertSame(1, $this->balance($child));

        $this->finish($child, $device, $token, 3, 7000)
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 0)
            ->assertJsonPath('data.improved', true)
            ->assertJsonPath('data.board.sizes.0.best_ms', 7000);

        $this->assertSame(1, $this->balance($child));
    }

    public function test_each_size_pays_only_inside_its_limit(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->finish($child, $device, $token, 3, 9001)->assertOk()->assertJsonPath('data.awarded_coins', 0);
        $this->finish($child, $device, $token, 3, 9000)->assertOk()->assertJsonPath('data.awarded_coins', 1);
        $this->finish($child, $device, $token, 4, 16_000)->assertOk()->assertJsonPath('data.awarded_coins', 2);
        $this->finish($child, $device, $token, 5, 25_001)->assertOk()->assertJsonPath('data.awarded_coins', 0);
        $this->finish($child, $device, $token, 5, 25_000)
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 3)
            ->assertJsonPath('data.improved', true);

        $this->assertSame(6, $this->balance($child));

        $this->finish($child, $device, $token, 5, 200)->assertStatus(422);
        $this->assertSame(6, $this->balance($child));
    }

    public function test_today_board_ranks_siblings_and_hides_other_families(): void
    {
        ['family' => $family, 'child' => $child, 'device' => $device, 'token' => $token] = $this->kid();
        $sibling = $this->makeChild($family, ['nickname' => '弟弟']);
        $scores = app(SchulteScoreService::class);

        $this->finish($child, $device, $token, 5, 20_000)->assertOk();
        $scores->record($sibling, $family, 5, 18_000);

        ['child' => $other] = $this->kid('baba');
        $scores->record($other, $other->family, 5, 10_000);

        $ranks = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/games/schulte')
            ->assertOk()
            ->json('data.sizes.2.ranks');

        $this->assertSame(['弟弟', '小星星'], array_column($ranks, 'nickname'));
        $this->assertSame([18_000, 20_000], array_column($ranks, 'best_ms'));
    }

    /**
     * @return array{family: Family, parent: User, child: ChildProfile, device: TrustedDevice, token: string}
     */
    private function kid(string $username = 'mama'): array
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily($username);
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        return compact('family', 'parent', 'child', 'device', 'token');
    }

    private function finish(ChildProfile $child, TrustedDevice $device, string $token, int $size, int $elapsedMs): TestResponse
    {
        return $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/schulte', ['size' => $size, 'elapsed_ms' => $elapsedMs]);
    }

    private function balance(ChildProfile $child): int
    {
        return (int) DB::table('point_accounts')->where('child_id', $child->id)->value('balance');
    }
}
