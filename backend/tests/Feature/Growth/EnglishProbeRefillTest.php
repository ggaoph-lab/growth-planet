<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishVocabularyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnglishProbeRefillTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_round_refills_probes_after_first_batch_is_done(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);

        $first = $service->plan($child);
        $this->assertNotEmpty($first['items']);
        foreach ($first['items'] as $card) {
            $answer = ! empty($card['challenge'])
                ? (string) ($card['correct_answer'] ?? $card['options'][0])
                : (string) ($card['review'] ? $card['text'] : $card['meaning']);
            $service->answer($child, $card['key'], $answer);
        }

        $emptyish = $service->plan($child);
        // Without refill this would stay empty after the first rich batch is answered.
        $this->assertNotEmpty($emptyish['items']);
        $this->assertTrue(collect($emptyish['items'])->contains(fn ($item) => ! empty($item['probe']) || ! empty($item['challenge'])));
        $probeCount = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'vocabulary_probe')
            ->where('business_date', '2026-10-13')
            ->value('items');
        $this->assertGreaterThan(5, count(json_decode((string) $probeCount, true) ?: []));
    }

    public function test_same_day_learning_keeps_refilling_past_the_ladder_size(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $answered = 0;
        for ($round = 0; $round < 8; $round++) {
            for ($i = 0; $i < EnglishLearningService::SESSION_SIZE; $i++) {
                $plan = $service->plan($child);
                $this->assertNotEmpty($plan['items'], "round {$round} slot {$i} should still have cards");
                $card = $plan['items'][0];
                $answer = ! empty($card['challenge'])
                    ? (string) ($card['correct_answer'] ?? $card['options'][0])
                    : (string) ($card['review'] ? $card['text'] : ($card['meaning'] ?? $card['options'][0]));
                $service->answer($child, $card['key'], $answer);
                $answered++;
            }
        }

        $this->assertGreaterThan(55, $answered);
        $keys = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '2026-10-13')
            ->where('item_key', 'like', 'probe:%')
            ->pluck('item_key');
        $this->assertTrue($keys->contains(fn ($key) => str_contains((string) $key, ':r')));
    }

    public function test_refill_does_not_sticky_repeat_garden_before_other_words(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);

        $first = $service->plan($child);
        $firstProbeTexts = collect($first['items'])
            ->filter(fn ($item) => ! empty($item['probe']))
            ->map(fn ($item) => strtolower((string) $item['text']))
            ->values()
            ->all();
        foreach ($first['items'] as $card) {
            $answer = ! empty($card['challenge'])
                ? (string) ($card['correct_answer'] ?? $card['options'][0])
                : (string) ($card['review'] ? $card['text'] : ($card['meaning'] ?? $card['options'][0]));
            $service->answer($child, $card['key'], $answer);
        }

        $second = $service->plan($child);
        $secondProbeTexts = collect($second['items'])
            ->filter(fn ($item) => ! empty($item['probe']))
            ->map(fn ($item) => strtolower((string) $item['text']))
            ->values()
            ->all();
        $this->assertNotEmpty($secondProbeTexts);
        $this->assertSame([], array_values(array_intersect($firstProbeTexts, $secondProbeTexts)));
        $this->assertLessThanOrEqual(1, count(array_filter($secondProbeTexts, fn ($text) => $text === 'garden')));
    }

    public function test_polluted_garden_round_keys_are_compacted_away(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $gardenCard = [
            'key' => 'probe:garden',
            'theme' => '词汇小测试',
            'kind' => 'word',
            'text' => 'garden',
            'meaning' => '花园',
            'emoji' => '🌷',
            'ipa' => '/ˈɡɑːrdn/',
            'pos' => 'n.',
            'options' => ['花园', '厨房', '教室'],
            'review' => false,
            'stage' => 0,
            'probe' => true,
        ];
        $hungryCard = [
            'key' => 'probe:hungry',
            'theme' => '词汇小测试',
            'kind' => 'word',
            'text' => 'hungry',
            'meaning' => '饥饿的',
            'emoji' => '🍽️',
            'ipa' => '/ˈhʌŋɡri/',
            'pos' => 'adj.',
            'options' => ['饥饿的', '口渴的', '困倦的'],
            'review' => false,
            'stage' => 0,
            'probe' => true,
        ];
        $polluted = [$gardenCard, $hungryCard];
        for ($round = 2; $round <= 20; $round++) {
            $polluted[] = [...$gardenCard, 'key' => 'probe:garden:r'.$round];
        }

        DB::table('english_ai_records')->insert([
            'child_id' => $child->id,
            'purpose' => 'vocabulary_probe',
            'business_date' => '2026-10-13',
            'profile_snapshot' => json_encode(['band' => 3], JSON_UNESCAPED_UNICODE),
            'items' => json_encode($polluted, JSON_UNESCAPED_UNICODE),
            'status' => 'local',
            'provider' => null,
            'model' => null,
            'created_at' => now(),
        ]);
        DB::table('english_learning_answers')->insert([
            'child_id' => $child->id,
            'item_key' => 'probe:garden',
            'business_date' => '2026-10-13',
            'correct' => true,
            'created_at' => now(),
        ]);

        $plan = $service->plan($child);
        $probeTexts = collect($plan['items'])
            ->filter(fn ($item) => ! empty($item['probe']))
            ->map(fn ($item) => strtolower((string) $item['text']))
            ->values()
            ->all();
        $this->assertNotContains('garden', $probeTexts);
        $this->assertNotEmpty($probeTexts);

        $items = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'vocabulary_probe')
            ->where('business_date', '2026-10-13')
            ->value('items'), true) ?: [];
        $unansweredGarden = collect($items)->filter(
            fn ($item) => ($item['text'] ?? '') === 'garden' && ($item['key'] ?? '') !== 'probe:garden'
        );
        $this->assertCount(0, $unansweredGarden);
    }
}
