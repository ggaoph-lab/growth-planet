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

        $this->seedDailyWordGoalDone($child, '2026-10-13');
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

    public function test_spelling_skip_moves_word_into_vocabulary_probe(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/words', ['word' => 'reluctant'])
            ->assertCreated();

        $this->seedDailyWordGoalDone($child, '2026-10-13');
        $plan = $service->plan($child);
        $spell = collect($plan['items'])->first(fn ($item) => ! empty($item['spelling']) && ($item['text'] ?? '') === 'reluctant');
        $this->assertNotNull($spell);

        $skip = $service->answer($child, $spell['key'], '__skip__');
        $this->assertFalse($skip['correct']);
        $this->assertTrue($skip['skipped'] ?? false);

        $after = $skip['plan'];
        $stillSpelling = collect($after['items'])->first(
            fn ($item) => ! empty($item['spelling']) && strtolower((string) ($item['text'] ?? '')) === 'reluctant'
        );
        $this->assertNull($stillSpelling);

        $probe = collect($after['items'])->first(
            fn ($item) => ! empty($item['probe'])
                && empty($item['spelling'])
                && strtolower((string) ($item['text'] ?? '')) === 'reluctant'
        );
        $this->assertNotNull($probe);
        $this->assertSame('词汇小测试', $probe['theme']);
        $this->assertContains('不情愿的', $probe['options']);
    }

    public function test_spelling_skip_does_not_reenter_spelling_same_day_after_probe_goal(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/words', ['word' => 'cloud'])
            ->assertCreated();
        $this->seedDailyWordGoalDone($child, '2026-10-13');

        $spell = collect($service->plan($child)['items'])->first(
            fn ($item) => ! empty($item['spelling']) && strtolower((string) ($item['text'] ?? '')) === 'cloud'
        );
        $this->assertNotNull($spell);
        $service->answer($child, $spell['key'], '__skip__');

        $probeKeys = [];
        for ($i = 0; $i < 12; $i++) {
            $plan = $service->plan($child);
            $probe = collect($plan['items'])->first(
                fn ($item) => ! empty($item['probe'])
                    && empty($item['spelling'])
                    && strtolower((string) ($item['text'] ?? '')) === 'cloud'
                    && ! in_array($item['key'], $probeKeys, true)
            );
            if ($probe === null) {
                $other = $plan['items'][0] ?? null;
                if ($other === null) {
                    break;
                }
                $answer = ! empty($other['spelling'])
                    ? (string) ($other['answer'] ?? $other['text'])
                    : (string) ($other['meaning'] ?? $other['options'][0] ?? '');
                if ($answer !== '') {
                    $service->answer($child, $other['key'], $answer);
                }
                continue;
            }
            $probeKeys[] = $probe['key'];
            $result = $service->answer($child, $probe['key'], $probe['meaning']);
            if (! empty($result['promoted_to_spelling'])) {
                $this->fail('spelling skip today must not promote back into spelling the same day');
            }
            if (($result['mastery_phase'] ?? null) === 'spell') {
                break;
            }
        }

        $this->assertSame('spell', $vocab->wordMastery($child, 'cloud')['phase']);
        $again = collect($service->plan($child)['items'])->first(
            fn ($item) => ! empty($item['spelling']) && strtolower((string) ($item['text'] ?? '')) === 'cloud'
        );
        $this->assertNull($again, 'cloud must not reappear as spelling after skip on the same day');
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
            'learning_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
        $this->seedDailyWordGoalDone($child, '2026-10-13');

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

        $this->seedDailyWordGoalDone($child, '2026-10-13');
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

    public function test_word_probe_needs_two_english_to_chinese_then_spelling(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $this->seedDailyWordGoalDone($child, '2026-10-13');

        $probe = collect($service->plan($child)['items'])->first(
            fn ($item) => ! empty($item['probe']) && empty($item['spelling']) && ($item['kind'] ?? '') === 'word'
        );
        $this->assertNotNull($probe);
        $word = (string) $probe['text'];

        $first = $service->answer($child, $probe['key'], $probe['meaning']);
        $this->assertTrue($first['correct']);
        $this->assertSame(1, $first['probe_corrects']);
        $this->assertFalse($first['promoted_to_spelling']);
        $this->assertSame(0, (int) DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
        $this->assertSame('mc', $vocab->wordMastery($child, $word)['phase']);

        $secondProbe = collect($first['plan']['items'])->first(
            fn ($item) => ! empty($item['probe'])
                && empty($item['spelling'])
                && strtolower((string) ($item['text'] ?? '')) === strtolower($word)
        );
        $this->assertNotNull($secondProbe, 'expected a second English→Chinese probe for the same word');
        $second = $service->answer($child, $secondProbe['key'], $secondProbe['meaning']);
        $this->assertTrue($second['correct']);
        $this->assertTrue($second['promoted_to_spelling']);
        $this->assertSame('spell', $vocab->wordMastery($child, $word)['phase']);
        $this->assertSame(0, (int) DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));

        $spell = collect($second['plan']['items'])->first(
            fn ($item) => ! empty($item['spelling']) && strtolower((string) ($item['text'] ?? '')) === strtolower($word)
        );
        $this->assertNotNull($spell, 'expected a spelling card after two English→Chinese corrects');
        $done = $service->answer($child, $spell['key'], $word);
        $this->assertTrue($done['correct']);
        $this->assertSame('done', $vocab->wordMastery($child, $word)['phase']);
        $this->assertSame(1, (int) DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
    }

    private function seedDailyWordGoalDone(object $child, string $date): void
    {
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:goal-seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => $date,
                    'due_date' => $date,
                    'completed_date' => $date,
                ],
            );
        }
        $this->markEnglishFairyHeard($child, $date);
    }
}
