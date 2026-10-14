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
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

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
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

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
        $fillAnswers = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '2026-10-13')
            ->where(function ($query) {
                $query->where('item_key', 'like', 'probe:%')
                    ->orWhere('item_key', 'like', 'spell:%');
            })
            ->count();
        $this->assertGreaterThan(40, $fillAnswers);
        $pool = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'vocabulary_probe')
            ->where('business_date', '2026-10-13')
            ->value('items'), true) ?: [];
        // Probe pool grows as sessions keep asking for more cards.
        $this->assertGreaterThan(EnglishLearningService::SESSION_SIZE, count($pool));
    }

    public function test_refill_does_not_sticky_repeat_garden_before_other_words(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

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
        // Mid-mastery rematches (2nd 英选中) may reappear at the back; fresh texts should still lead.
        $overlap = array_values(array_unique(array_intersect($firstProbeTexts, $secondProbeTexts)));
        $freshSecond = array_values(array_filter(
            $secondProbeTexts,
            fn ($text) => ! in_array($text, $firstProbeTexts, true),
        ));
        $this->assertNotEmpty($freshSecond, 'Second round should introduce some new probe words.');
        $this->assertGreaterThanOrEqual(
            count($freshSecond),
            count($overlap),
            'Fresh probe texts should at least match rematch count so the queue does not sticky-loop.',
        );
        $this->assertLessThanOrEqual(1, count(array_filter($secondProbeTexts, fn ($text) => $text === 'garden')));
    }

    public function test_answered_sentences_are_not_wrapped_back_from_ladder(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);

        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

        $answeredSentences = [];
        for ($step = 0; $step < 30; $step++) {
            $plan = $service->plan($child);
            $this->assertNotEmpty($plan['items']);
            $card = $plan['items'][0];
            if (($card['kind'] ?? '') === 'sentence') {
                $text = strtolower((string) $card['text']);
                $this->assertNotContains(
                    $text,
                    $answeredSentences,
                    "sentence \"{$text}\" must not reappear after being answered today",
                );
                $answeredSentences[] = $text;
            }
            $answer = ! empty($card['challenge'])
                ? (string) ($card['correct_answer'] ?? $card['options'][0])
                : (! empty($card['spelling'])
                    ? (string) ($card['answer'] ?? $card['text'])
                    : (string) ($card['review'] ? $card['text'] : ($card['meaning'] ?? $card['options'][0])));
            $service->answer($child, $card['key'], $answer);
        }

        $this->assertNotEmpty($answeredSentences);
        $counts = array_count_values($answeredSentences);
        foreach ($counts as $text => $count) {
            $this->assertSame(1, $count, "sentence \"{$text}\" answered {$count} times");
        }
    }

    public function test_local_rich_path_does_not_sticky_loop_yellow_and_cloud(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $vocab = app(EnglishVocabularyService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $vocab->setPath($child, EnglishVocabularyService::PATH_RICH);

        // Finish the daily word pack + fairy tale so the queue is probe/spelling filled from the local ladder.
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

        $seenPair = 0;
        $lastPair = null;
        for ($step = 0; $step < 24; $step++) {
            $plan = $service->plan($child);
            $this->assertNotEmpty($plan['items'], "step {$step} should still offer cards");
            $card = $plan['items'][0];
            $text = strtolower((string) ($card['text'] ?? ''));
            $pair = $text === 'yellow' || $text === 'cloud' ? $text : null;
            if ($pair !== null && $lastPair !== null && $pair !== $lastPair) {
                $seenPair++;
            }
            $lastPair = $pair;
            $answer = ! empty($card['challenge'])
                ? (string) ($card['correct_answer'] ?? $card['options'][0])
                : (! empty($card['spelling'])
                    ? (string) ($card['answer'] ?? $card['text'])
                    : (string) ($card['review'] ? $card['text'] : ($card['meaning'] ?? $card['options'][0])));
            $service->answer($child, $card['key'], $answer);
        }

        // A healthy queue may revisit yellow/cloud, but must not oscillate them as the only front cards.
        $this->assertLessThan(10, $seenPair, 'yellow↔cloud front oscillation should stay rare');

        $probeTexts = collect($service->plan($child)['items'])
            ->filter(fn ($item) => ! empty($item['probe']) && empty($item['spelling']))
            ->map(fn ($item) => strtolower((string) $item['text']))
            ->unique()
            ->values()
            ->all();
        $this->assertNotEmpty($probeTexts);
        $onlyYellowCloud = $probeTexts === ['yellow', 'cloud'] || $probeTexts === ['cloud', 'yellow'];
        $this->assertFalse($onlyYellowCloud, 'probe queue must keep other expressions besides yellow/cloud');
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
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:goal-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');

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
