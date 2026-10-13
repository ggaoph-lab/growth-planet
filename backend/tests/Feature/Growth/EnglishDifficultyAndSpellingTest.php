<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishVocabularyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnglishDifficultyAndSpellingTest extends TestCase
{
    use RefreshDatabase;

    public function test_practice_band_stays_easy_until_accuracy_unlocks(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $known = [];
        for ($i = 0; $i < 100; $i++) {
            $known[] = 'word'.$i;
        }
        DB::table('english_vocabulary_profiles')->insert([
            'child_id' => $child->id,
            'estimated_size' => 100,
            'band' => 4,
            'path' => 'standard',
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode([], JSON_UNESCAPED_UNICODE),
            'learning_words' => json_encode([], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $vocab->practiceBand($child));

        for ($i = 0; $i < 10; $i++) {
            DB::table('english_learning_answers')->insert([
                'child_id' => $child->id,
                'item_key' => 'probe:x'.$i,
                'business_date' => '2026-10-13',
                'correct' => $i < 9,
                'created_at' => now(),
            ]);
        }
        // 90% over 10 answers → accuracy cap 4, size band 4.
        $this->assertSame(4, $vocab->practiceBand($child));
    }

    public function test_kid_can_save_sentence_word_and_get_spelling_card(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/words', ['word' => 'garden'])
            ->assertCreated()
            ->assertJsonPath('data.word', 'garden');

        $plan = $service->plan($child);
        $spell = collect($plan['items'])->first(fn ($item) => ! empty($item['spelling']));
        $this->assertNotNull($spell);
        $this->assertSame('garden', $spell['text']);
        $this->assertNotEmpty($spell['blank_hint']);
        $this->assertStringNotContainsString(' ', str_replace("\u{00A0}", '', $spell['blank_hint']));

        $wrong = $service->answer($child, $spell['key'], 'gardin');
        $this->assertFalse($wrong['correct']);
        $right = $service->answer($child, $spell['key'], 'garden');
        $this->assertTrue($right['correct']);
    }

    public function test_daily_spelling_draws_at_most_five_learned_words(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $known = ['bird', 'fish', 'tree', 'sun', 'mom', 'dad', 'big', 'small', 'run', 'egg'];
        DB::table('english_vocabulary_profiles')->insert([
            'child_id' => $child->id,
            'estimated_size' => count($known),
            'band' => 1,
            'path' => 'standard',
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode([], JSON_UNESCAPED_UNICODE),
            'learning_words' => json_encode([], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $first = collect($service->plan($child)['items'])->where('spelling', true)->pluck('text')->values();
        $this->assertLessThanOrEqual(EnglishVocabularyService::SPELLING_DAILY_LIMIT, $first->count());
        $this->assertSame(EnglishVocabularyService::SPELLING_DAILY_LIMIT, $first->count());

        $second = collect($service->plan($child)['items'])->where('spelling', true)->pluck('text')->sort()->values();
        $this->assertSame($first->sort()->values()->all(), $second->all());

        foreach ($first as $word) {
            $this->assertContains($word, $known);
        }
        $this->assertSame(1, DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'spelling_day')
            ->where('business_date', '2026-10-13')
            ->count());
        $this->assertSame(5, count(json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'spelling_day')
            ->value('items'), true)));
        unset($vocab);
    }

    public function test_new_probe_pool_prefers_easy_band_words(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $plan = $service->plan($child);
        $probeTexts = collect($plan['items'])
            ->filter(fn ($item) => ! empty($item['probe']) && empty($item['spelling']))
            ->pluck('text')
            ->map(fn ($text) => strtolower((string) $text))
            ->all();
        $this->assertNotEmpty($probeTexts);
        foreach ($probeTexts as $text) {
            $this->assertFalse(in_array($text, ['reluctant', 'perspective', 'negotiate', 'adventure'], true), $text);
        }
    }
}
