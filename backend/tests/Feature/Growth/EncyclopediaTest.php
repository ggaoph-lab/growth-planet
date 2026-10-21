<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Encyclopedia\Services\EncyclopediaCatalog;
use App\Modules\Encyclopedia\Services\EncyclopediaProgress;
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
        $summary = $catalog->subjectSummary();
        $this->assertGreaterThanOrEqual(10, $summary['topic_count']);
        $this->assertNotEmpty($summary['domains']);
        $domainKey = $summary['domains'][0]['key'];
        $topics = $catalog->topicsForDomain($domainKey);
        $this->assertNotEmpty($topics);
        $topicId = $topics[0]['id'];

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
            ->assertJsonPath('data.read', false);

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

    public function test_unpublished_and_unknown_topics_are_not_found(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/encyclopedia/topics/mt_not_a_real_topic')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $draftId = 'mt_7OJjLOl0fz';
        $this->assertFalse(app(EncyclopediaCatalog::class)->isPublished($draftId));
        $this->getJson('/api/v1/kid/encyclopedia/topics/'.$draftId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/kid/encyclopedia/domains/not-a-domain')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_reviewed_science_copy_avoids_known_errors(): void
    {
        $catalog = app(EncyclopediaCatalog::class);
        $seasons = $catalog->topicDetail('mt_K0mZxY2AM8');
        $this->assertNotNull($seasons);
        $this->assertStringNotContainsString('离太阳近', $seasons['body']);
        $minibeasts = $catalog->topicDetail('mt_yR1moI5kX1');
        $this->assertNotNull($minibeasts);
        $this->assertStringContainsString('蜗牛不是昆虫', $minibeasts['body']);
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
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $topicId = array_key_first(app(EncyclopediaCatalog::class)->publishedTopics());
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
