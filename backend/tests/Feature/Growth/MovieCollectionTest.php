<?php

namespace Tests\Feature\Growth;

use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieAgeReview;
use App\Modules\Movie\Models\MovieApproval;
use App\Modules\Movie\Models\MovieCollection;
use App\Modules\Movie\Models\MovieCollectionItem;
use App\Modules\Movie\Models\MovieProgress;
use App\Modules\Movie\Models\MovieRewardEvent;
use App\Modules\Movie\Services\MovieCollectionService;
use App\Modules\Movie\Services\MovieWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovieCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_series_folds_and_sorts_for_kid(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '系列一', 'release_year' => 2010]);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '系列二', 'release_year' => 2012]);

        app(MovieCollectionService::class)->createManual($family->id, '测试系列', null, [
            ['movie_id' => $m1->id, 'position_story' => 1],
            ['movie_id' => $m2->id, 'position_story' => 2],
        ], 'story');

        $data = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('collection', $data[0]['kind']);
        $this->assertSame('测试系列', $data[0]['collection']['name']);
        $this->assertSame(2, $data[0]['collection']['visible_count']);
        $this->assertArrayNotHasKey('movie_count', $data[0]['collection']);
        $this->assertSame($m1->id, $data[0]['movies'][0]['id']);
        $this->assertSame($m2->id, $data[0]['movies'][1]['id']);
    }

    public function test_only_first_approved_does_not_leak_second(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '第一部']);
        $m2 = Movie::query()->create([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '秘密续集',
            'runtime_minutes' => 100,
            'data_completeness' => 'complete',
        ]);
        $review = MovieAgeReview::query()->create([
            'movie_id' => $m2->id,
            'safety_status' => MovieSafetyStatus::ApprovedSuitable,
            'min_age' => 6,
            'reviewer_id' => $parent->id,
            'created_at' => now(),
        ]);
        $m2->current_review_id = $review->id;
        $m2->save();

        $collection = app(MovieCollectionService::class)->createManual($family->id, '隐藏系列', null, [
            ['movie_id' => $m1->id, 'position_story' => 1],
            ['movie_id' => $m2->id, 'position_story' => 2],
        ]);

        $kid = $this->actingAsKid($child->fresh(['member.user']), $device, $token);

        $list = $kid->getJson('/api/v1/kid/movies')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('movie', $list[0]['kind']);
        $this->assertSame($m1->id, $list[0]['movie']['id']);
        $payload = json_encode($list, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('秘密续集', $payload);
        $this->assertStringNotContainsString('隐藏系列', $payload);

        $kid->getJson('/api/v1/kid/movies/'.$m2->id)->assertNotFound();
        $kid->getJson('/api/v1/kid/movie-collections/'.$collection->id)->assertNotFound();

        $search = $kid->getJson('/api/v1/kid/movies?q=秘密')->assertOk()->json('data');
        $this->assertSame([], $search);
    }

    public function test_progress_and_rewards_are_per_movie_within_series(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->ensureMediaRewardPolicies($family);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '甲']);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '乙']);

        app(MovieCollectionService::class)->createManual($family->id, '双片系列', null, [
            ['movie_id' => $m1->id, 'position_story' => 1],
            ['movie_id' => $m2->id, 'position_story' => 2],
        ]);

        $watch = app(MovieWatchService::class);
        $lib1 = $watch->addToLibrary($child, $m1->id);
        $lib2 = $watch->addToLibrary($child, $m2->id);

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/movie-library/'.$lib1->id.'/sessions/start', ['position_start' => 0])
            ->assertCreated();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/movie-library/'.$lib2->id.'/sessions/start', ['position_start' => 10])
            ->assertCreated();

        $this->putJson('/api/v1/kid/movie-library/'.$lib1->id.'/progress', ['position_seconds' => 120])
            ->assertOk();
        $this->putJson('/api/v1/kid/movie-library/'.$lib2->id.'/progress', ['position_seconds' => 45])
            ->assertOk();

        $this->assertSame(120, MovieProgress::query()->findOrFail($lib1->id)->position_seconds);
        $this->assertSame(45, MovieProgress::query()->findOrFail($lib2->id)->position_seconds);

        $this->assertSame(1, MovieRewardEvent::query()->where('library_id', $lib1->id)->where('kind', 'FIRST_START')->count());
        $this->assertSame(1, MovieRewardEvent::query()->where('library_id', $lib2->id)->where('kind', 'FIRST_START')->count());
    }

    public function test_parent_edit_series_keeps_watch_records_and_tmdb_respects_lock(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->ensureMediaRewardPolicies($family);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, [
            'title_zh' => '一部',
            'source' => 'tmdb',
            'external_id' => '101',
            'release_year' => 2001,
        ]);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, [
            'title_zh' => '二部',
            'source' => 'tmdb',
            'external_id' => '102',
            'release_year' => 2003,
        ]);

        $service = app(MovieCollectionService::class);
        $collection = $service->linkMovieFromTmdb($m1, '9001', '官方系列', 1);
        $service->linkMovieFromTmdb($m2, '9001', '官方系列', 2);

        $watch = app(MovieWatchService::class);
        $lib1 = $watch->addToLibrary($child, $m1->id);
        $watch->startSession($child, $lib1, 0);
        $watch->updatePosition($child, $lib1, 333);

        $this->actingAsParent($parent)->putJson('/api/v1/parent/movie-collections/'.$collection->id.'/items', [
            'items' => [
                ['movie_id' => $m2->id, 'position_story' => 1, 'position_custom' => 1],
                ['movie_id' => $m1->id, 'position_story' => 2, 'position_custom' => 2],
            ],
        ])->assertOk()->assertJsonPath('data.structure_locked', true);

        $this->assertSame(333, MovieProgress::query()->findOrFail($lib1->id)->position_seconds);

        $collection = MovieCollection::query()->findOrFail($collection->id);
        $service->applyTmdbSnapshot($collection, [
            'external_id' => '9001',
            'name' => 'TMDB 想覆盖的名字',
            'overview' => 'new overview',
            'parts' => [
                ['external_id' => '101', 'release_year' => 2001],
                ['external_id' => '102', 'release_year' => 2003],
                ['external_id' => '103', 'release_year' => 2005],
            ],
        ]);

        $collection->refresh();
        $this->assertSame(2, MovieCollectionItem::query()->where('collection_id', $collection->id)->count());
        $this->assertTrue($collection->structure_locked);

        $this->actingAsParent($parent)->putJson('/api/v1/parent/movie-collections/'.$collection->id, [
            'name' => '家长命名',
        ])->assertOk();

        $service->applyTmdbSnapshot($collection->fresh(), [
            'external_id' => '9001',
            'name' => '又想覆盖',
            'overview' => 'x',
            'parts' => [],
        ]);
        $this->assertSame('家长命名', $collection->fresh()->name);
    }

    public function test_unapproved_sequel_not_in_kid_catalog(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '正片']);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '续集']);

        MovieApproval::query()->where('movie_id', $m2->id)->where('child_id', $child->id)->update([
            'visible' => false,
            'revoked_at' => now(),
        ]);

        app(MovieCollectionService::class)->createManual($family->id, '系列', null, [
            ['movie_id' => $m1->id],
            ['movie_id' => $m2->id],
        ]);

        $data = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/movies')
            ->assertOk()
            ->json('data');

        $flat = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('正片', $flat);
        $this->assertStringNotContainsString('续集', $flat);
    }

    public function test_parent_recommendations_aggregate_by_collection(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => 'A1']);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => 'A2']);
        $solo = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '独立片']);

        app(MovieCollectionService::class)->createManual($family->id, '聚合系列', null, [
            ['movie_id' => $m1->id],
            ['movie_id' => $m2->id],
        ]);

        $data = $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/movies/recommendations')
            ->assertOk()
            ->json('data');

        $kinds = array_column($data, 'kind');
        $this->assertContains('collection', $kinds);
        $this->assertContains('movie', $kinds);
        $collectionCard = collect($data)->firstWhere('kind', 'collection');
        $this->assertCount(2, $collectionCard['movies']);
        $this->assertSame($solo->id, collect($data)->firstWhere('kind', 'movie')['movie']['id']);
    }

    public function test_parent_can_create_collection_via_api(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => 'X1']);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => 'X2']);

        $this->actingAsParent($parent)->postJson('/api/v1/parent/movie-collections', [
            'name' => 'API 系列',
            'sort_mode' => 'custom',
            'items' => [
                ['movie_id' => $m1->id, 'position_custom' => 1],
                ['movie_id' => $m2->id, 'position_custom' => 2],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'API 系列')
            ->assertJsonPath('data.movie_count', 2)
            ->assertJsonPath('data.structure_locked', true);
    }

    public function test_parent_can_delete_collection_and_movie_without_losing_progress(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->ensureMediaRewardPolicies($family);

        $m1 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '可删一']);
        $m2 = $this->seedVisibleMovie($family, $child, $parent, ['title_zh' => '可删二']);

        $collection = app(MovieCollectionService::class)->createManual($family->id, '待删系列', null, [
            ['movie_id' => $m1->id],
            ['movie_id' => $m2->id],
        ]);

        $watch = app(MovieWatchService::class);
        $lib1 = $watch->addToLibrary($child, $m1->id);
        $watch->updatePosition($child, $lib1, 88);

        $this->actingAsParent($parent)
            ->deleteJson('/api/v1/parent/movie-collections/'.$collection->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('movie_collections', ['id' => $collection->id]);
        $this->assertDatabaseMissing('movie_collection_items', ['movie_id' => $m1->id]);
        $this->assertSame(88, MovieProgress::query()->findOrFail($lib1->id)->position_seconds);

        $this->actingAsParent($parent)
            ->deleteJson('/api/v1/parent/movies/'.$m1->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('movies', ['id' => $m1->id]);
        $this->assertSame(88, MovieProgress::query()->findOrFail($lib1->id)->position_seconds);
        $ids = collect($this->actingAsParent($parent)
            ->getJson('/api/v1/parent/movies')
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($m1->id));
        $this->assertTrue($ids->contains($m2->id));
    }

    public function test_parent_suggestion_wall_has_cards_without_a_library(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $data = $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/movies/suggestions')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(8, count($data));
        $this->assertNotEmpty($data[0]['title_zh']);
        $this->assertSame('builtin', $data[0]['source']);
        $this->assertStringStartsWith('/api/v1/parent/movie-posters/', $data[0]['poster_url']);
    }

    public function test_adding_toy_story_2_imports_the_whole_series(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $data = $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/movies/suggestions/toy-story-2/adopt', ['child_id' => $child->id])
            ->assertCreated()
            ->json('data');

        $titles = collect($data['movies'])->pluck('title_zh')->sort()->values()->all();
        $this->assertSame(['玩具总动员', '玩具总动员2', '玩具总动员3', '玩具总动员4'], $titles);
        $this->assertSame('玩具总动员', $data['collection']['name']);
        $this->assertSame(4, MovieCollectionItem::query()->where('collection_id', $data['collection']['id'])->count());
        $this->assertSame(
            4,
            MovieApproval::query()->where('child_id', $child->id)->where('visible', true)->count(),
        );
        $this->assertNotNull(Movie::query()->where('family_id', $family->id)->where('title_zh', '玩具总动员2')->value('poster_key'));
    }
}
