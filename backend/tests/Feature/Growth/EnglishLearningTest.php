<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishVocabularyService;
use App\Modules\Ledger\Models\RewardPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnglishLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_rewards_once_and_schedules_spaced_reviews(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 23:30:00', 'Asia/Shanghai'));
        $firstPlan = $service->plan($child);
        $this->assertSame(EnglishVocabularyService::NEW_WORDS_SESSION_CAP, count($firstPlan['items']));
        $this->assertSame(10, $firstPlan['new_words_goal']);
        $this->assertSame(0, $firstPlan['new_words_completed']);
        $this->assertFalse($firstPlan['fairy_tale_unlocked']);
        $this->assertFalse($firstPlan['review_unlocked']);
        $card = collect($firstPlan['items'])->first(fn ($item) => ! empty($item['daily_word']) || str_starts_with((string) $item['key'], 'word:'));
        $this->assertNotNull($card);
        $first = $service->answer($child, $card['key'], $card['meaning']);
        $this->assertTrue($first['correct']);
        $this->assertSame(1, $first['coins']);
        $this->assertSame('2026-10-14', $first['next_review']);
        $this->assertSame(1, $first['plan']['new_words_completed']);
        try {
            $service->answer($child, $card['key'], $card['meaning']);
            $this->fail('A completed card must not pay again.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('reward_grants')->where('policy_code', 'english.practice')->count());
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-14 00:30:00', 'Asia/Shanghai'));
        // New words + fairy tale gate reviews; finish both so the due review can enter the queue.
        for ($i = 0; $i < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:goal-seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => '2026-10-14',
                    'due_date' => '2026-10-15',
                    'completed_date' => '2026-10-14',
                ],
            );
        }
        $waitingFairy = $service->plan($child);
        $this->assertTrue($waitingFairy['fairy_tale_unlocked']);
        $this->assertFalse($waitingFairy['review_unlocked']);
        $this->assertSame([], $waitingFairy['items']);
        $this->markEnglishFairyHeard($child, '2026-10-14');
        $review = collect($service->plan($child)['items'])->firstWhere('key', $card['key']);
        $this->assertNotNull($review);
        $this->assertTrue($review['review']);
        $this->assertContains($card['text'], $review['options']);
        $second = $service->answer($child, $card['key'], $card['text']);
        $this->assertSame('2026-10-17', $second['next_review']);
    }

    public function test_same_day_miss_keeps_learning_options_for_retry(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $card = collect($service->plan($child)['items'])->first(
            fn ($item) => empty($item['probe']) && empty($item['spelling']) && empty($item['challenge'])
        );
        $this->assertNotNull($card);
        $this->assertFalse($card['review'] ?? false);
        $wrongOption = collect($card['options'])->first(fn ($option) => $option !== $card['meaning']);
        $this->assertNotNull($wrongOption);

        $wrong = $service->answer($child, $card['key'], (string) $wrongOption);
        $this->assertFalse($wrong['correct']);
        $again = collect($wrong['plan']['items'])->firstWhere('key', $card['key']);
        $this->assertNotNull($again);
        $this->assertFalse($again['review'] ?? false);
        $this->assertContains($card['meaning'], $again['options']);
        $this->assertContains((string) $wrongOption, $again['options']);

        $right = $service->answer($child, $card['key'], (string) $card['meaning']);
        $this->assertTrue($right['correct']);
    }

    public function test_wrong_answers_reset_progress_and_session_keeps_refilling(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $plan = $service->plan($child);
        $card = $plan['items'][0];
        $wrongOption = collect($card['options'])->first(fn ($option) => $option !== $card['meaning']);
        $wrong = $service->answer($child, $card['key'], (string) $wrongOption);
        $this->assertFalse($wrong['correct']);
        $this->assertSame(0, $wrong['coins']);
        $this->assertSame(0, DB::table('reward_grants')->count());
        $this->assertTrue($service->answer($child, $card['key'], $card['text'])['correct']);

        $second = collect($service->plan($child)['items'])->first(fn ($item) => ($item['key'] ?? '') !== $card['key']);
        $this->assertNotNull($second);
        $service->answer($child, $second['key'], $second['meaning']);

        $this->seedCompletedNewWords($child, '2026-10-13', 10);
        $this->markEnglishFairyHeard($child, '2026-10-13');
        $after = $service->plan($child);
        $this->assertGreaterThanOrEqual(10, $after['new_words_completed']);
        $this->assertTrue($after['review_unlocked']);
        $this->assertNotEmpty($after['items']);
        $probe = collect($after['items'])->first(fn ($item) => ! empty($item['probe']) && empty($item['spelling']));
        $this->assertNotNull($probe);
        $wrongProbe = collect($probe['options'])->first(
            fn ($option) => $option !== $probe['meaning'] && $option !== ($probe['text'] ?? '')
        );
        $this->assertNotNull($wrongProbe);
        $probeResult = $service->answer($child, $probe['key'], (string) $wrongProbe);
        $this->assertFalse($probeResult['correct']);
        $this->assertSame(0, $probeResult['coins']);
        $this->assertGreaterThan(0, $probeResult['plan']['vocabulary']['estimated_size']);
        $other = $this->makeChild($family, ['nickname' => '另一个孩子']);
        $this->assertSame(0, $service->plan($other)['learned']);
    }

    public function test_learning_continues_after_ten_and_rewards_still_cap(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        RewardPolicy::query()->where('family_id', $family->id)->where('code', 'english.practice')->update(['is_active' => false]);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        foreach (array_slice(config('english_learning.items'), 0, 12) as $item) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => $item['key'],
                'stage' => 1,
                'introduced_date' => '2026-01-01',
                'due_date' => '2026-01-02',
            ]);
        }
        $this->seedCompletedNewWords($child, '2026-10-13', 10);
        $this->markEnglishFairyHeard($child, '2026-10-13');
        for ($i = 0; $i < 3; $i++) {
            $card = $service->plan($child)['items'][0];
            $answer = $service->answer($child, $card['key'], $card['review'] ? $card['text'] : $card['meaning']);
            $this->assertSame(0, $answer['coins']);
        }
        $this->assertDatabaseCount('reward_grants', 0);

        RewardPolicy::query()->where('family_id', $family->id)->where('code', 'english.practice')->update([
            'is_active' => true,
            'max_per_day' => EnglishLearningService::REWARD_DAILY_LIMIT,
        ]);
        for ($i = 0; $i < 12; $i++) {
            $card = collect($service->plan($child)['items'])->first(
                fn ($item) => empty($item['probe']) && empty($item['challenge']) && empty($item['spelling'])
            );
            if ($card === null) {
                break;
            }
            $service->answer($child, $card['key'], $card['review'] ? $card['text'] : $card['meaning']);
        }
        $coinTotal = (int) DB::table('reward_grants')->where('policy_code', 'english.practice')->sum('coin_amount');
        $this->assertGreaterThan(0, $coinTotal);
        $this->assertLessThanOrEqual(EnglishLearningService::REWARD_DAILY_LIMIT, $coinTotal);
        $this->assertNotEmpty($service->plan($child)['items']);
    }

    public function test_kid_api_validates_answers_and_replays_idempotently(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $plan = $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/english/learning')
            ->assertOk()
            ->assertJsonCount(EnglishVocabularyService::NEW_WORDS_SESSION_CAP, 'data.items')
            ->assertJsonPath('data.new_words_goal', 10)
            ->assertJsonPath('data.review_unlocked', false)
            ->json('data');
        $card = $plan['items'][0];
        $key = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/kid/english/learning/answer', ['key' => $card['key'], 'answer' => $card['meaning']])
                ->assertOk()
                ->assertJsonPath('data.correct', true);
        }
        $this->assertDatabaseCount('english_learning_answers', 1);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/english/learning/answer', ['key' => $card['key'], 'answer' => 'made up'])
            ->assertUnprocessable();
    }

    public function test_new_word_slots_are_reserved_ahead_of_review_backlog(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        foreach (array_slice(config('english_learning.items'), 0, 12) as $item) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => $item['key'],
                'stage' => 1,
                'introduced_date' => '2026-10-01',
                'due_date' => '2026-10-13',
                'completed_date' => null,
            ]);
        }

        $plan = $service->plan($child);
        $daily = collect($plan['items'])->filter(
            fn ($item) => (! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:'))
                && empty($item['review'])
        );
        // Each round only introduces a short batch of new words.
        $this->assertSame(EnglishVocabularyService::NEW_WORDS_SESSION_CAP, $daily->count());
        $this->assertSame(0, $plan['new_words_completed']);
        $this->assertLessThanOrEqual(EnglishLearningService::REVIEW_DAILY_LIMIT, $plan['due']);
        $this->assertSame(12, $plan['due_total']);

        // Finish today's new-word goal across rounds, then reviews come from a random pool of ≤10.
        $answered = 0;
        while ($answered < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL) {
            $batch = collect($service->plan($child)['items'])->filter(
                fn ($item) => (! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:'))
                    && empty($item['review'])
            );
            $this->assertLessThanOrEqual(EnglishVocabularyService::NEW_WORDS_SESSION_CAP, $batch->count());
            $this->assertNotEmpty($batch);
            foreach ($batch as $card) {
                $service->answer($child, $card['key'], $card['meaning']);
                $answered++;
                if ($answered >= EnglishVocabularyService::NEW_WORDS_DAILY_GOAL) {
                    break;
                }
            }
        }
        $waitingFairy = $service->plan($child);
        $this->assertSame(10, $waitingFairy['new_words_completed']);
        $this->assertTrue($waitingFairy['fairy_tale_unlocked']);
        $this->assertFalse($waitingFairy['review_unlocked']);
        $this->assertSame([], $waitingFairy['items']);
        $this->markEnglishFairyHeard($child, '2026-10-13');
        $after = $service->plan($child);
        $this->assertTrue($after['review_unlocked']);
        $this->assertLessThanOrEqual(EnglishLearningService::REVIEW_DAILY_LIMIT, $after['due']);
        $reviewKeys = collect($after['items'])->filter(fn ($item) => ! empty($item['review']))->pluck('key');
        $this->assertLessThanOrEqual(EnglishLearningService::REVIEW_DAILY_LIMIT, $reviewKeys->count());
        $this->assertSame(
            $reviewKeys->sort()->values()->all(),
            collect($service->plan($child)['items'])->filter(fn ($item) => ! empty($item['review']))->pluck('key')->sort()->values()->all(),
            'daily review pool should be stable within the day'
        );
    }

    public function test_records_endpoint_lists_new_words_and_fairy_tales(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $card = collect($service->plan($child)['items'])->first(
            fn ($item) => ! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:')
        );
        $this->assertNotNull($card);
        $service->answer($child, $card['key'], $card['meaning']);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/english/learning/records')
            ->assertOk()
            ->assertJsonPath('data.new_words_completed', 1)
            ->assertJsonFragment(['text' => $card['text'], 'status' => 'done'])
            ->assertJsonStructure([
                'data' => [
                    'completed_today',
                    'new_words_today',
                    'learned',
                    'mastered',
                    'known_words',
                    'fairy_tales',
                ],
            ]);
    }

    public function test_daily_word_pack_is_always_stored_with_ten_words(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $plan = $service->plan($child);
        $pack = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->where('business_date', '2026-10-13')
            ->value('items'), true) ?: [];
        $this->assertCount(EnglishVocabularyService::NEW_WORDS_DAILY_GOAL, $pack);
        $this->assertSame(
            EnglishVocabularyService::NEW_WORDS_SESSION_CAP,
            collect($plan['items'])->filter(fn ($item) => ! empty($item['daily_word']))->count()
        );
    }

    public function test_previously_learned_word_is_replaced_in_cached_daily_pack(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        DB::table('english_learning_progress')->insert([
            'child_id' => $child->id, 'item_key' => 'word:bird', 'stage' => 4,
            'introduced_date' => '2026-09-01', 'due_date' => '2026-10-13',
            'completed_date' => '2026-09-29',
        ]);
        DB::table('english_ai_records')->insert([
            'child_id' => $child->id, 'purpose' => 'daily_word_pack',
            'business_date' => '2026-10-13', 'profile_snapshot' => '{}',
            'items' => json_encode([[
                'kind' => 'word', 'text' => 'bird', 'meaning' => '鸟', 'emoji' => '🐦',
                'ipa' => '', 'pos' => 'n.', 'options' => ['鸟', '鱼', '树'],
            ]], JSON_UNESCAPED_UNICODE),
            'status' => 'local', 'provider' => null, 'model' => null, 'created_at' => now(),
        ]);

        $plan = app(EnglishLearningService::class)->plan($child);
        $this->assertFalse(collect($plan['items'])->contains(fn ($item) => ($item['text'] ?? '') === 'bird'));
        $this->assertSame(0, $plan['due_total']);
        $this->assertSame(10, $plan['new_words_goal']);
    }

    public function test_exhausted_local_ladder_does_not_recycle_old_words_as_new(): void
    {
        config(['services.openai.api_key' => '', 'services.qwen.api_key' => '', 'services.gemini.api_key' => '']);
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        foreach (config('english_vocabulary.bands') as $entries) {
            foreach ($entries as $entry) {
                if (($entry['kind'] ?? '') !== 'word') {
                    continue;
                }
                DB::table('english_learning_progress')->insert([
                    'child_id' => $child->id,
                    'item_key' => 'word:'.strtolower($entry['text']),
                    'stage' => 4, 'introduced_date' => '2026-09-01',
                    'due_date' => '2026-10-13', 'completed_date' => '2026-09-29',
                ]);
            }
        }

        $plan = app(EnglishLearningService::class)->plan($child);
        $this->assertSame(0, $plan['new_words_goal']);
        $this->assertSame(0, $plan['due_total']);
        $this->assertFalse(collect($plan['items'])->contains(fn ($item) => ! empty($item['daily_word'])));
        $masteredWords = collect(config('english_vocabulary.bands'))->flatten(1)
            ->filter(fn ($entry) => ($entry['kind'] ?? '') === 'word')->pluck('text')->all();
        $this->assertFalse(collect($plan['items'])->contains(
            fn ($item) => ($item['kind'] ?? '') === 'word' && in_array($item['text'] ?? '', $masteredWords, true)
        ));
    }

    public function test_short_daily_word_pack_is_topped_up_so_queue_does_not_stall(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $short = [];
        foreach (['cat', 'dog', 'bird', 'fish', 'tree', 'book'] as $text) {
            $short[] = [
                'kind' => 'word',
                'text' => $text,
                'meaning' => $text.'义',
                'emoji' => '📘',
                'ipa' => '/x/',
                'pos' => 'n.',
                'options' => [$text.'义', '错一', '错二'],
            ];
        }
        DB::table('english_ai_records')->insert([
            'child_id' => $child->id,
            'purpose' => 'daily_word_pack',
            'business_date' => '2026-10-13',
            'profile_snapshot' => json_encode(['band' => 1], JSON_UNESCAPED_UNICODE),
            'items' => json_encode($short, JSON_UNESCAPED_UNICODE),
            'status' => 'local',
            'provider' => null,
            'model' => null,
            'created_at' => now(),
        ]);
        foreach ($short as $item) {
            $slug = $item['text'];
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:'.$slug,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }

        $plan = $service->plan($child);
        $this->assertSame(6, $plan['new_words_completed']);
        $this->assertFalse($plan['fairy_tale_unlocked']);
        $this->assertNotEmpty($plan['items'], 'short pack must be topped up so learning can continue');
        $daily = collect($plan['items'])->filter(
            fn ($item) => ! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:')
        );
        $this->assertNotEmpty($daily);
        $this->assertTrue(
            $daily->every(fn ($item) => ! in_array((string) $item['text'], ['cat', 'dog', 'bird', 'fish', 'tree', 'book'], true)),
            'top-up words should be new unfinished words'
        );
        $items = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->where('business_date', '2026-10-13')
            ->value('items'), true) ?: [];
        $this->assertGreaterThan(6, count($items));
        $this->assertSame(1, DB::table('english_ai_records')->where('purpose', 'daily_word_pack')->count());
    }

    public function test_daily_word_pack_caches_once_and_unlocks_fairy_tale_at_ten(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $first = $service->plan($child);
        $second = $service->plan($child);
        $this->assertSame(1, DB::table('english_ai_records')->where('purpose', 'daily_word_pack')->count());
        $this->assertSame(
            collect($first['items'])->where('daily_word', true)->pluck('key')->all(),
            collect($second['items'])->where('daily_word', true)->pluck('key')->all(),
        );

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/english/learning/fairy-tale')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'need_new_words');

        $answered = 0;
        while ($answered < EnglishVocabularyService::NEW_WORDS_DAILY_GOAL) {
            $plan = $service->plan($child);
            $card = collect($plan['items'])->first(fn ($item) => ! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:'));
            $this->assertNotNull($card, 'expected a daily word card while under goal');
            $service->answer($child, $card['key'], $card['meaning']);
            $answered++;
        }

        $unlocked = $service->plan($child);
        $this->assertTrue($unlocked['fairy_tale_unlocked']);
        $this->assertSame(10, $unlocked['new_words_completed']);

        $tale = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/english/learning/fairy-tale')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.heard', false)
            ->json('data');
        $this->assertNotEmpty($tale['tale']['zh_lines']);
        $this->assertNotEmpty($tale['tale']['en_lines']);
        $this->assertSame(1, DB::table('english_ai_records')->where('purpose', 'fairy_tale_day')->count());

        $heardKey = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $heardKey)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/heard')
            ->assertOk()
            ->assertJsonPath('data.heard', true);
        $this->withHeader('Idempotency-Key', $heardKey)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/heard')
            ->assertOk()
            ->assertJsonPath('data.heard', true);
        $this->assertSame(1, DB::table('english_learning_answers')->where('item_key', 'fairy-tale:heard')->count());
    }

    public function test_fairy_tale_word_can_be_added_and_practiced_from_today_new_words(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));
        $this->seedCompletedNewWords($child, '2026-10-13', 10);

        $tale = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/english/learning/fairy-tale')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->json('data.tale');
        $word = $this->pickTaleContentWord($tale['en_lines'] ?? [], (string) ($tale['title_en'] ?? ''));
        $before = count(json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->value('items'), true) ?: []);

        $saved = $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/words', ['word' => $word])
            ->assertOk()
            ->assertJsonPath('data.added', true)
            ->assertJsonPath('data.word', $word)
            ->json('data');

        $after = json_decode((string) DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->value('items'), true) ?: [];
        $this->assertSame($before + 1, count($after));
        $this->assertSame(1, DB::table('english_ai_records')->where('purpose', 'daily_word_pack')->count());

        $focused = $service->plan($child, $saved['key']);
        $this->assertSame($saved['key'], $focused['items'][0]['key'] ?? null);
        $this->assertSame(10, $focused['new_words_completed']);

        $service->answer($child, $saved['key'], $saved['meaning']);
        $done = $service->plan($child);
        $this->assertSame(11, $done['new_words_completed']);
        $this->assertFalse(collect($done['items'])->contains(fn ($item) => ($item['key'] ?? '') === $saved['key']));

        $record = collect($service->records($child)['new_words_today'])->firstWhere('key', $saved['key']);
        $this->assertNotNull($record);
        $this->assertSame('done', $record['status']);
        $this->assertTrue($record['collected']);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/words', ['word' => $word])
            ->assertOk()
            ->assertJsonPath('data.added', false)
            ->assertJsonPath('data.learned', true);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/words', ['word' => 'the'])
            ->assertStatus(422);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/english/learning/fairy-tale/words', ['word' => 'zebra'])
            ->assertStatus(422);
    }

    /**
     * @param  list<string>  $lines
     */
    private function pickTaleContentWord(array $lines, string $title): string
    {
        $skip = array_fill_keys([
            'a', 'an', 'the', 'is', 'am', 'are', 'was', 'were', 'be', 'to', 'of', 'and', 'or',
            'in', 'on', 'at', 'my', 'your', 'i', 'you', 'we', 'he', 'she', 'it', 'they',
            'this', 'that', 'do', 'does', 'did', 'can', 'will', 'her', 'his', 'him', 'me',
            'us', 'them', 'our', 'their', 'its',
        ], true);
        $blob = $title."\n".implode("\n", $lines);
        preg_match_all("/[A-Za-z']+/", $blob, $matches);
        foreach ($matches[0] as $token) {
            $word = strtolower($token);
            $word = (string) preg_replace("/'s$/", '', $word);
            if (strlen($word) >= 4 && preg_match('/^[a-z]+$/', $word) === 1 && ! isset($skip[$word])) {
                return $word;
            }
        }

        $this->fail('Fairy tale did not contain a content word to collect.');
    }

    /**
     * @param  list<int>|int  $count
     */
    private function seedCompletedNewWords(object $child, string $date, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => $date,
                    'due_date' => $date,
                    'completed_date' => $date,
                ],
            );
        }
    }
}
