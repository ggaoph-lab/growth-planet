<?php

namespace Tests\Feature\Growth;

use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieAgeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovieVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_gets_404_for_movie_not_on_whitelist(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $hidden = Movie::query()->create([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '未开放电影',
            'data_completeness' => 'insufficient',
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies/'.$hidden->id)
            ->assertNotFound();
    }

    public function test_kid_can_see_whitelisted_movie(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $movie = $this->seedVisibleMovie($family, $child, $parent);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies/'.$movie->id)
            ->assertOk()
            ->assertJsonPath('data.id', $movie->id);
    }

    public function test_parent_opening_a_movie_puts_it_on_the_child_shelf(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $movie = Movie::query()->create([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '已开放的电影',
            'original_title' => 'Opened Movie',
            'overview' => '一部关于勇气的故事',
            'release_year' => 2018,
            'runtime_minutes' => 90,
            'data_completeness' => 'insufficient',
        ]);
        $review = MovieAgeReview::query()->create([
            'movie_id' => $movie->id,
            'safety_status' => MovieSafetyStatus::ApprovedSuitable,
            'min_age' => 6,
            'reviewer_id' => $parent->id,
            'created_at' => now(),
        ]);
        $movie->current_review_id = $review->id;
        $movie->data_completeness = 'complete';
        $movie->save();

        $this->assertDatabaseMissing('child_movie_library', [
            'child_id' => $child->id,
            'movie_id' => $movie->id,
        ]);

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/movies/'.$movie->id.'/approvals/'.$child->id, ['visible' => true])
            ->assertOk();

        $this->assertDatabaseHas('child_movie_library', [
            'child_id' => $child->id,
            'movie_id' => $movie->id,
            'shelf' => 'want',
        ]);
    }

    public function test_already_visible_movie_is_on_the_shelf_when_the_child_opens_movies(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $movie = $this->seedVisibleMovie($family, $child, $parent, [
            'title_zh' => '早就开放',
            'overview' => '一部关于勇气的故事',
        ]);

        $this->assertDatabaseMissing('child_movie_library', ['movie_id' => $movie->id]);

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies')
            ->assertOk()
            ->assertJsonPath('data.0.movie.id', $movie->id)
            ->assertJsonPath('data.0.movie.overview', '一部关于勇气的故事')
            ->assertJsonPath('data.0.movie.library.shelf', 'want');
    }

    public function test_approval_for_a_too_young_child_does_not_fail_or_add_a_shelf_row(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2024-06-01']);

        $movie = Movie::query()->create([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '年纪还不够',
            'runtime_minutes' => 90,
            'data_completeness' => 'insufficient',
        ]);
        $review = MovieAgeReview::query()->create([
            'movie_id' => $movie->id,
            'safety_status' => MovieSafetyStatus::ApprovedSuitable,
            'min_age' => 6,
            'reviewer_id' => $parent->id,
            'created_at' => now(),
        ]);
        $movie->current_review_id = $review->id;
        $movie->data_completeness = 'complete';
        $movie->save();

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/movies/'.$movie->id.'/approvals/'.$child->id, ['visible' => true])
            ->assertOk();

        $this->assertDatabaseMissing('child_movie_library', [
            'child_id' => $child->id,
            'movie_id' => $movie->id,
        ]);
    }

    public function test_builtin_movie_intro_stays_bilingual_when_the_saved_copy_is_chinese_only(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $movie = $this->seedVisibleMovie($family, $child, $parent, [
            'title_zh' => '海洋奇缘',
            'external_id' => 'moana-1',
            'poster_key' => 'moana-1',
            'overview' => '旧的中文介绍',
        ]);

        $overview = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies')
            ->assertOk()
            ->json('data.0.movie.overview');

        $this->assertIsString($overview);
        $this->assertStringContainsString('莫阿娜', $overview);
        $this->assertStringContainsString('Moana answers the ocean', $overview);
        $this->assertStringNotContainsString('旧的中文介绍', $overview);
        $this->assertStringContainsString('Moana answers the ocean', (string) $movie->fresh()->getRawOriginal('overview'));
    }

    public function test_parent_can_mark_a_movie_suitable_without_reentering_password(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $movie = Movie::query()->create([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '手动添加',
            'runtime_minutes' => 80,
            'data_completeness' => 'insufficient',
        ]);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/movies/'.$movie->id.'/reviews', [
                'safety_status' => MovieSafetyStatus::ApprovedSuitable->value,
                'min_age' => 6,
                'reason' => '家长录入',
            ])
            ->assertCreated();
    }
}
