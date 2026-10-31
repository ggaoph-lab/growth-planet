<?php

namespace Tests\Feature\Growth;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\TrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GameLevelRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_mirror_pays_once_per_level_per_day(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/games/mirror')
            ->assertOk()
            ->assertJsonPath('data.levels.0.id', 'easy')
            ->assertJsonPath('data.levels.0.coins', 1)
            ->assertJsonPath('data.levels.0.awarded', false)
            ->assertJsonPath('data.levels.1.coins', 2)
            ->assertJsonPath('data.levels.2.coins', 3);

        $this->claim($child, $device, $token, 'mirror', 'easy')
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 1)
            ->assertJsonPath('data.board.levels.0.awarded', true);

        $this->assertSame(1, $this->balance($child));

        $this->claim($child, $device, $token, 'mirror', 'easy')
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 0);

        $this->assertSame(1, $this->balance($child));

        $this->claim($child, $device, $token, 'mirror', 'practice')
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 2);
        $this->claim($child, $device, $token, 'mirror', 'challenge')
            ->assertOk()
            ->assertJsonPath('data.awarded_coins', 3);

        $this->assertSame(6, $this->balance($child));
    }

    public function test_sudoku_rewards_are_separate_from_mirror(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->claim($child, $device, $token, 'mirror', 'easy')->assertOk()->assertJsonPath('data.awarded_coins', 1);
        $this->claim($child, $device, $token, 'sudoku', 'easy')->assertOk()->assertJsonPath('data.awarded_coins', 1);
        $this->claim($child, $device, $token, 'sudoku', 'challenge')->assertOk()->assertJsonPath('data.awarded_coins', 3);

        $this->assertSame(5, $this->balance($child));

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/games/sudoku')
            ->assertOk()
            ->assertJsonPath('data.levels.0.awarded', true)
            ->assertJsonPath('data.levels.2.awarded', true)
            ->assertJsonPath('data.levels.1.awarded', false);
    }

    public function test_daily_slots_are_isolated_and_cannot_be_reused(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();
        $start = fn (string $game, string $level) => $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/'.$game.'/start', ['level' => $level]);

        $first = $start('mirror', 'easy')->assertOk()
            ->assertJsonPath('data.remaining', 1)->json('data.play_id');
        $start('mirror', 'easy')->assertOk()->assertJsonPath('data.remaining', 0);
        $start('mirror', 'easy')->assertStatus(429)->assertJsonPath('error.code', 'GAME_DAILY_LIMIT');
        $start('mirror', 'practice')->assertOk()->assertJsonPath('data.remaining', 1);
        $start('sudoku', 'easy')->assertOk()->assertJsonPath('data.remaining', 1);

        $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/mirror', ['level' => 'easy', 'play_id' => $first])
            ->assertOk()->assertJsonPath('data.awarded_coins', 1);
        $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/mirror', ['level' => 'easy', 'play_id' => $first])
            ->assertStatus(422)->assertJsonPath('error.code', 'GAME_PLAY_INVALID');
        $this->assertSame(1, $this->balance($child));
    }

    public function test_unknown_game_is_rejected(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/games/chess')
            ->assertNotFound();
    }

    /**
     * @return array{child: ChildProfile, device: TrustedDevice, token: string}
     */
    private function kid(string $username = 'mama'): array
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily($username);
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        return compact('child', 'device', 'token');
    }

    private function claim(
        ChildProfile $child,
        TrustedDevice $device,
        string $token,
        string $game,
        string $level,
    ): TestResponse {
        $playId = $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/'.$game.'/start', ['level' => $level])
            ->assertOk()->json('data.play_id');
        return $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/games/'.$game, ['level' => $level, 'play_id' => $playId]);
    }

    private function balance(ChildProfile $child): int
    {
        return (int) DB::table('point_accounts')->where('child_id', $child->id)->value('balance');
    }
}
