<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishContentGate;
use App\Modules\English\Services\EnglishDraftService;
use App\Modules\English\Services\EnglishLearningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnglishLabCoachTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_gate_rejects_bad_probe_and_accepts_valid_grammar(): void
    {
        $gate = app(EnglishContentGate::class);
        $this->assertNull($gate->probeCard([
            'text' => 'this is a very long sentence that should not pass band one',
            'meaning' => '太长',
            'options' => ['太长', '短', '中'],
        ], [], 1));
        $word = $gate->probeCard([
            'text' => 'bird',
            'meaning' => '鸟',
            'options' => ['鸟', '鱼', '树'],
            'emoji' => '🐦',
            'ipa' => '/bɜːrd/',
            'pos' => 'n.',
            'kind' => 'word',
        ], [], 1);
        $this->assertNotNull($word);
        $this->assertSame('/bɜːrd/', $word['ipa']);
        $this->assertSame('n.', $word['pos']);
        $sentence = $gate->probeCard([
            'text' => 'I see a bird.',
            'meaning' => '我看见一只鸟。',
            'options' => ['我看见一只鸟。', '我想喝水。', '这是猫。'],
            'emoji' => '👀',
            'kind' => 'sentence',
        ], [], 1);
        $this->assertNotNull($sentence);
        $this->assertSame('sentence', $sentence['kind']);
        $draft = $gate->challengeDraft('grammar', [
            'prompt' => 'I ___ happy.',
            'text' => 'I ___ happy.',
            'question' => '选正确的词',
            'options' => ['am', 'is', 'are'],
            'answer' => 'am',
            'tip' => 'I 后面用 am。',
            'emoji' => '🧩',
        ], 1);
        $this->assertNotNull($draft);
        $this->assertSame(['key2', 'key1'], $gate->reorderKeys(['key1', 'key2'], ['key2', 'key1', 'key2']));
        $this->assertNull($gate->reorderKeys(['key1', 'key2'], ['key1']));
    }

    public function test_new_cards_keep_a_sentence_and_expose_ai_status(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $plan = $service->plan($child);
        $newCards = array_values(array_filter($plan['items'], fn ($item) => empty($item['probe']) && empty($item['challenge']) && empty($item['review'])));
        $this->assertNotEmpty($newCards);
        $this->assertTrue(collect($newCards)->contains(fn ($item) => ($item['kind'] ?? '') === 'word'));
        $daily = collect($newCards)->first(fn ($item) => ! empty($item['daily_word']) || str_starts_with((string) ($item['key'] ?? ''), 'word:'));
        $this->assertNotNull($daily);
        $this->assertNotSame('', (string) ($daily['ipa'] ?? ''));
        $this->assertNotSame('', (string) ($daily['pos'] ?? ''));
        $this->assertContains($plan['ai_status'], ['ok', 'failed', 'off']);
        $this->assertSame(10, $plan['new_words_goal']);
    }

    public function test_wrong_answer_returns_coach_tip_without_reward(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $card = $service->plan($child)['items'][0];
        $wrong = collect($card['options'])->first(fn ($option) => $option !== $card['meaning']);
        $result = $service->answer($child, $card['key'], (string) $wrong);
        $this->assertFalse($result['correct']);
        $this->assertSame(0, $result['coins']);
        $this->assertNotSame('', $result['coach_tip']);
        $this->assertStringContainsString((string) $card['text'], (string) $result['coach_tip']);
    }

    public function test_wrong_answer_uses_local_tip_without_llm_wait(): void
    {
        config([
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.model' => 'gpt-4o-mini',
        ]);
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        app(\App\Modules\Core\Settings\Settings::class)->set('ai.provider', 'openai', $family->id);
        \Illuminate\Support\Facades\Http::fake([
            'https://openai.test/*' => function () {
                usleep(200_000);

                return \Illuminate\Support\Facades\Http::response([
                    'choices' => [['message' => ['content' => '{"tip":"来自模型的慢提示"}']]],
                ]);
            },
        ]);

        $service = app(EnglishLearningService::class);
        $card = $service->plan($child)['items'][0];
        $wrong = collect($card['options'])->first(fn ($option) => $option !== $card['meaning']);
        $started = microtime(true);
        $result = $service->answer($child, $card['key'], (string) $wrong);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertFalse($result['correct']);
        $this->assertStringContainsString((string) $card['text'], (string) $result['coach_tip']);
        $this->assertLessThan(500, $elapsedMs, 'kid answer must not wait on coach LLM');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_correct_answer_reuses_plan_without_second_full_rebuild(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $before = $service->plan($child);
        $this->assertGreaterThanOrEqual(2, count($before['items']));
        $card = $before['items'][0];
        $secondKey = (string) $before['items'][1]['key'];

        $started = microtime(true);
        $result = $service->answer($child, (string) $card['key'], (string) $card['meaning']);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertTrue($result['correct']);
        $keys = array_map(static fn ($item) => (string) ($item['key'] ?? ''), $result['plan']['items'] ?? []);
        $this->assertNotContains((string) $card['key'], $keys);
        $this->assertContains($secondKey, $keys);
        $this->assertLessThan(800, $elapsedMs, 'correct course answer should skip a second full plan rebuild');
    }

    public function test_local_ai_record_retries_after_provider_switch(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $coach = app(\App\Modules\English\Services\EnglishAiCoach::class);

        $local = (object) ['status' => 'local', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'created_at' => now()];
        $this->assertFalse($coach->shouldRetryLocal($child, $local));

        app(\App\Modules\Core\Settings\Settings::class)->set('ai.provider', 'qwen', (int) $family->id, null);
        $this->assertTrue($coach->shouldRetryLocal($child, $local));
        $this->assertFalse($coach->shouldRetryLocal($child, (object) ['status' => 'ai', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'created_at' => now()]));
    }

    public function test_local_ai_record_retries_after_model_change_or_cooldown(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $settings = app(\App\Modules\Core\Settings\Settings::class);
        $coach = app(\App\Modules\English\Services\EnglishAiCoach::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Shanghai'));

        $settings->set('ai.provider', 'gemini', (int) $family->id, null);
        $settings->set('ai.gemini_model', 'gemini-2.5-flash', (int) $family->id, null);

        $freshLocal = (object) [
            'status' => 'local',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'created_at' => '2026-10-14 09:55:00',
        ];
        $this->assertFalse($coach->shouldRetryLocal($child, $freshLocal));

        $settings->set('ai.gemini_model', 'gemini-2.5-flash-lite', (int) $family->id, null);
        $this->assertTrue($coach->shouldRetryLocal($child, $freshLocal));

        $settings->set('ai.gemini_model', 'gemini-2.5-flash', (int) $family->id, null);
        $staleLocal = (object) [
            'status' => 'local',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'created_at' => app(\App\Modules\Core\Support\Clock::class)->now()->subMinutes(15),
        ];
        $this->assertTrue($coach->shouldRetryLocal($child, $staleLocal));
    }

    public function test_failure_hint_and_plan_expose_sanitized_ai_error(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $settings = app(\App\Modules\Core\Settings\Settings::class);
        $coach = app(\App\Modules\English\Services\EnglishAiCoach::class);
        $settings->set('ai.provider', 'gemini', (int) $family->id, null);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Shanghai'));

        DB::table('english_ai_records')->insert([
            'child_id' => $child->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'purpose' => 'session_plan',
            'business_date' => '2026-10-14',
            'profile_snapshot' => json_encode(['error' => 'http_429'], JSON_UNESCAPED_UNICODE),
            'items' => json_encode(['order' => ['apple']], JSON_UNESCAPED_UNICODE),
            'status' => 'local',
            'created_at' => '2026-10-14 09:55:00',
        ]);

        $this->assertSame('failed', $coach->connectionStatus($child, '2026-10-14'));
        $this->assertSame('http_429', $coach->failureHint($child, '2026-10-14'));

        config(['services.gemini.api_key' => '']);
        $plan = app(EnglishLearningService::class)->plan($child);
        $this->assertSame('failed', $plan['ai_status']);
        $this->assertIsString($plan['ai_error']);
        $this->assertNotSame('', $plan['ai_error']);
    }

    public function test_published_grammar_enters_kid_queue_without_coins(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $drafts = app(EnglishDraftService::class);
        $learning = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        foreach (array_slice(config('english_learning.items'), 0, 18) as $item) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => $item['key'],
                'stage' => 4,
                'introduced_date' => '2026-01-01',
                'due_date' => '2026-12-01',
                'completed_date' => null,
            ]);
        }
        for ($i = 0; $i < \App\Modules\English\Services\EnglishVocabularyService::NEW_WORDS_DAILY_GOAL; $i++) {
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

        $draft = $drafts->generate($child, 'grammar');
        $this->assertSame('pending', $draft['status']);
        $published = $drafts->publish($child, $draft['id']);
        $this->assertSame('published', $published['status']);

        $plan = $learning->plan($child);
        $challenge = collect($plan['items'])->first(fn ($item) => ! empty($item['challenge']));
        $this->assertNotNull($challenge);
        $this->assertSame('grammar:'.$draft['id'], $challenge['key']);

        $answer = $learning->answer($child, $challenge['key'], (string) $challenge['correct_answer']);
        $this->assertTrue($answer['correct']);
        $this->assertSame(0, $answer['coins']);
        $this->assertSame(0, $answer['xp']);
        $this->assertDatabaseCount('reward_grants', 0);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/english/learning?child_id='.$child->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['completed_today', 'vocabulary' => ['estimated_size', 'band'], 'missed_words']]);
        $this->actingAsParent($parent)->getJson('/api/v1/parent/english/learning/summary?child_id='.$child->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['week_start', 'summary', 'focuses', 'status']]);
        $this->actingAsParent($parent)->getJson('/api/v1/parent/english/learning/drafts?child_id='.$child->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $draft['id'], 'status' => 'published']);
    }
}
