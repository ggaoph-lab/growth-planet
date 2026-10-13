<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishVocabularyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnglishRichPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_rich_path_skips_starter_catalog_and_raises_band(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $standard = $service->plan($child);
        $this->assertTrue(collect($standard['items'])->contains(fn ($item) => ($item['key'] ?? '') === 'apple'));

        $response = $this->actingAsParent($parent)->putJson('/api/v1/parent/english/learning/path', [
            'child_id' => $child->id,
            'path' => 'rich',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.path', 'rich');
        // Rich path starts gently; harder bands unlock only after strong accuracy.
        $response->assertJsonPath('data.vocabulary.effective_band', 2);

        $profile = DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->first();
        $this->assertSame('rich', $profile->path);
        $this->assertGreaterThanOrEqual(2, (int) $profile->band);

        $plan = $service->plan($child);
        $this->assertSame('rich', $plan['vocabulary']['path']);
        $this->assertGreaterThanOrEqual(2, $plan['vocabulary']['effective_band']);
        $this->assertFalse(collect($plan['items'])->contains(fn ($item) => ($item['key'] ?? '') === 'apple'));
        $this->assertTrue(collect($plan['items'])->contains(fn ($item) => ! empty($item['probe'])));
        // Grammar/story challenges wait until accuracy unlocks band 3+.
        $this->assertFalse(
            collect($plan['items'])->contains(fn ($item) => str_starts_with((string) ($item['key'] ?? ''), 'grammar:rich-'))
        );
    }

    public function test_rich_path_still_returns_due_catalog_reviews(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $service->answer($child, 'apple', '苹果');
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Shanghai'));
        $plan = $service->plan($child);
        $review = collect($plan['items'])->firstWhere('key', 'apple');
        $this->assertNotNull($review);
        $this->assertTrue($review['review']);
    }
}
