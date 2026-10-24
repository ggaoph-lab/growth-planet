<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Encyclopedia\Services\EncyclopediaCatalog;
use App\Modules\Encyclopedia\Services\EncyclopediaProgress;
use App\Modules\English\Services\EnglishVocabularyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncyclopediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_browses_science_domains_and_marks_topics_read(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $catalog = app(EncyclopediaCatalog::class);
        $summary = $catalog->subjectSummary($child);
        $this->assertGreaterThanOrEqual(10, $summary['topic_count']);
        $this->assertNotEmpty($summary['domains']);
        $domainKey = $summary['domains'][0]['key'];
        $topics = $catalog->topicsForDomain($domainKey, [], $child);
        $this->assertNotEmpty($topics);
        $topicId = collect($topics)->firstWhere('locked', false)['id'] ?? $topics[0]['id'];
        if (($topics[0]['locked'] ?? false) === true) {
            // Unlock by reading any listed unlock hints until the first open topic is free.
            $progress = app(EncyclopediaProgress::class);
            $open = collect($topics)->first(fn ($row) => ($row['locked'] ?? false) === false);
            $this->assertNotNull($open);
            $topicId = $open['id'];
        }

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia')
            ->assertOk()
            ->assertJsonPath('data.subject', 'Science')
            ->assertJsonPath('data.topic_count', $summary['topic_count']);

        $this->getJson('/api/v1/kid/encyclopedia/domains/'.$domainKey)
            ->assertOk()
            ->assertJsonPath('data.key', $domainKey)
            ->assertJsonCount(count($topics), 'data.topics');

        $this->getJson('/api/v1/kid/encyclopedia/topics/'.$topicId)
            ->assertOk()
            ->assertJsonPath('data.id', $topicId)
            ->assertJsonPath('data.read', false)
            ->assertJsonStructure([
                'data' => [
                    'name_zh', 'name_en', 'body_zh', 'body_en', 'words', 'video', 'locked',
                ],
            ]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/encyclopedia/topics/'.$topicId.'/read')
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertDatabaseHas('encyclopedia_reads', [
            'child_id' => $child->id,
            'topic_id' => $topicId,
        ]);

        $this->getJson('/api/v1/kid/encyclopedia/topics/'.$topicId)
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }

    public function test_draft_overlays_are_published_without_reviewed_flag(): void
    {
        $catalog = app(EncyclopediaCatalog::class);
        $draftId = 'mt_7OJjLOl0fz';
        $this->assertTrue($catalog->isPublished($draftId));
        $this->assertFalse(($catalog->overlay()[$draftId]['reviewed'] ?? false) === true);
    }

    public function test_unknown_and_missing_overlay_topics_are_not_found(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/mt_not_a_real_topic')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $firstMissing = collect(app(EncyclopediaCatalog::class)->topicsById())
            ->keys()
            ->first(fn ($id) => ! app(EncyclopediaCatalog::class)->isPublished((string) $id));
        $this->assertNotNull($firstMissing);

        $this->getJson('/api/v1/kid/encyclopedia/topics/'.$firstMissing)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/kid/encyclopedia/domains/not-a-domain')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_age_filter_hides_out_of_range_topics(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        // Age 7 on 2026-10-24; materials topic mt_5r47Pvstyn is ages 5–6 only.
        $child = $this->makeChild($family, ['birth_date' => '2019-09-01']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));

        $catalog = app(EncyclopediaCatalog::class);
        $this->assertTrue($catalog->isPublished('mt_5r47Pvstyn'));
        $this->assertFalse($catalog->isVisibleToChild('mt_5r47Pvstyn', $child));

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/mt_5r47Pvstyn')
            ->assertNotFound();
    }

    public function test_prerequisite_locks_topic_until_read(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2019-09-01']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));

        $lockedId = 'mt_-0cjwyYhce';
        $prereqId = 'mt_FZ_ixwU1p1';
        $catalog = app(EncyclopediaCatalog::class);
        $this->assertTrue($catalog->isVisibleToChild($lockedId, $child));
        $this->assertTrue($catalog->isLockedForChild($lockedId, $child, []));

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/'.$lockedId)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TOPIC_LOCKED');

        app(EncyclopediaProgress::class)->markRead($child, $prereqId);

        $this->getJson('/api/v1/kid/encyclopedia/topics/'.$lockedId)
            ->assertOk()
            ->assertJsonPath('data.id', $lockedId)
            ->assertJsonPath('data.locked', false);
    }

    public function test_video_and_words_payload_shape(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2019-09-01']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/mt_oAg79ju344')
            ->assertOk()
            ->assertJsonPath('data.video.provider', 'bilibili')
            ->assertJsonPath('data.video.id', 'BV1xx411c7mD')
            ->assertJsonFragment(['word' => 'ocean', 'meaning' => '海洋']);
    }

    public function test_kid_can_collect_encyclopedia_word_into_daily_pack(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2019-09-01']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));

        // Ensure daily pack exists before collect.
        app(EnglishVocabularyService::class)->dailyWordPack($child, '2026-10-24');
        $before = count(json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->value('items'), true) ?: []);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/encyclopedia/topics/mt_oAg79ju344/words', ['word' => 'ocean'])
            ->assertOk()
            ->assertJsonPath('data.word', 'ocean')
            ->assertJsonPath('data.added', true)
            ->assertJsonPath('data.meaning', '海洋');

        $after = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->value('items'), true) ?: [];
        $this->assertSame($before + 1, count($after));

        $this->postJson('/api/v1/kid/encyclopedia/topics/mt_oAg79ju344/words', ['word' => 'zebra'])
            ->assertStatus(422);
    }

    public function test_reviewed_science_copy_avoids_known_errors(): void
    {
        $catalog = app(EncyclopediaCatalog::class);
        $seasons = $catalog->topicDetail('mt_K0mZxY2AM8');
        $this->assertNotNull($seasons);
        $this->assertStringNotContainsString('离太阳近', (string) $seasons['body_zh']);
        $minibeasts = $catalog->topicDetail('mt_yR1moI5kX1');
        $this->assertNotNull($minibeasts);
        $this->assertStringContainsString('蜗牛不是昆虫', (string) $minibeasts['body_zh']);
    }

    public function test_disabled_encyclopedia_planet_blocks_kid_api(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('planets.encyclopedia_planet', false, $family->id);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PLANET_DISABLED');
    }

    public function test_mark_read_is_idempotent_for_same_child(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2019-09-01']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));

        $topics = app(EncyclopediaCatalog::class)->topicsForDomain(
            app(EncyclopediaCatalog::class)->subjectSummary($child)['domains'][0]['key'],
            [],
            $child,
        );
        $topicId = collect($topics)->first(fn ($row) => ($row['locked'] ?? false) === false)['id'] ?? null;
        $this->assertNotNull($topicId);

        $progress = app(EncyclopediaProgress::class);
        $progress->markRead($child, $topicId);
        $progress->markRead($child, $topicId);

        $this->assertSame(1, DB::table('encyclopedia_reads')->where('child_id', $child->id)->where('topic_id', $topicId)->count());

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/'.$topicId)
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }
}
