<?php

namespace Tests\Feature\Growth;

use App\Modules\Ledger\Models\RewardGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovieViewingTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_can_move_backward_and_language_can_change(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->ensureMediaRewardPolicies($family);
        $movie = $this->seedVisibleMovie($family, $child, $parent, [
            'title_zh' => '海洋奇缘',
            'runtime_minutes' => 100,
        ]);

        $kid = $this->actingAsKid($child->fresh(['member.user']), $device, $token);
        $libraryId = $kid->getJson('/api/v1/kid/movies')->assertOk()->json('data.0.movie.library.id');
        $viewingId = $kid->getJson('/api/v1/kid/movies')->json('data.0.movie.library.viewings.0.id');

        $kid->putJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings', [
            'viewing_id' => $viewingId,
            'position_percent' => 80,
            'language' => 'zh',
        ])->assertOk()->assertJsonPath('data.viewings.0.position_percent', 80);

        $kid->putJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings', [
            'viewing_id' => $viewingId,
            'position_percent' => 25,
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.viewings.0.position_percent', 25)
            ->assertJsonPath('data.viewings.0.language', 'en')
            ->assertJsonPath('data.reward', null);

        $this->assertSame(0, RewardGrant::query()->where('policy_code', 'movie.viewing')->count());
        $this->assertSame($movie->id, (int) DB::table('child_movie_library')->where('id', $libraryId)->value('movie_id'));
    }

    public function test_adding_a_watch_adds_a_bar_and_only_the_first_four_pay(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->ensureMediaRewardPolicies($family);
        $this->seedVisibleMovie($family, $child, $parent, ['runtime_minutes' => 90]);

        $kid = $this->actingAsKid($child->fresh(['member.user']), $device, $token);
        $libraryId = $kid->getJson('/api/v1/kid/movies')->assertOk()->json('data.0.movie.library.id');
        $firstId = $kid->getJson('/api/v1/kid/movies')->json('data.0.movie.library.viewings.0.id');

        $kid->postJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings/'.$firstId.'/complete', [
            'position_percent' => 89,
        ])->assertStatus(422);

        $coins = [];
        $viewingId = $firstId;
        for ($pass = 1; $pass <= 5; $pass++) {
            if ($pass > 1) {
                $created = $kid->postJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings', ['language' => $pass % 2 === 0 ? 'en' : 'zh'])
                    ->assertCreated();
                $viewingId = $created->json('data.viewings.'.($pass - 1).'.id');
                $this->assertCount($pass, $created->json('data.viewings'));
            }

            $done = $kid->postJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings/'.$viewingId.'/complete', [
                'position_percent' => 90,
            ])->assertOk();
            $coins[] = $done->json('data.reward.coins');
            $this->assertSame('COMPLETED', $done->json('data.viewings.'.($pass - 1).'.status'));
        }

        $this->assertSame([5, 5, 5, 5, null], $coins);
        $this->assertSame(20, (int) DB::table('coin_accounts')->where('child_id', $child->id)->value('balance'));
        $this->assertSame(4, RewardGrant::query()->where('child_id', $child->id)->where('policy_code', 'movie.viewing')->count());
    }

    public function test_rewarded_progress_cannot_be_edited(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->ensureMediaRewardPolicies($family);
        $this->seedVisibleMovie($family, $child, $parent, ['runtime_minutes' => 95]);

        $kid = $this->actingAsKid($child->fresh(['member.user']), $device, $token);
        $libraryId = $kid->getJson('/api/v1/kid/movies')->assertOk()->json('data.0.movie.library.id');
        $viewingId = $kid->getJson('/api/v1/kid/movies')->json('data.0.movie.library.viewings.0.id');

        $kid->postJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings/'.$viewingId.'/complete', [
            'position_percent' => 96,
        ])->assertOk()
            ->assertJsonPath('data.reward.coins', 5)
            ->assertJsonPath('data.viewings.0.status', 'COMPLETED');

        $kid->putJson('/api/v1/kid/movie-library/'.$libraryId.'/viewings', [
            'viewing_id' => $viewingId,
            'position_percent' => 40,
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.viewings.0.position_percent', 96)
            ->assertJsonPath('data.viewings.0.language', 'zh')
            ->assertJsonPath('data.viewings.0.status', 'COMPLETED')
            ->assertJsonPath('data.viewings.0.rewarded', true);

        $this->assertSame(1, RewardGrant::query()->where('child_id', $child->id)->where('policy_code', 'movie.viewing')->count());
    }
}
