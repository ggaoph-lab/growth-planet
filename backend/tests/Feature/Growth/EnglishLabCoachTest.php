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
        $this->assertTrue(collect($newCards)->contains(fn ($item) => ($item['kind'] ?? '') === 'sentence'));
        $apple = collect($newCards)->firstWhere('key', 'apple');
        $this->assertNotNull($apple);
        $this->assertSame('/ˈæpl/', $apple['ipa']);
        $this->assertSame('n.', $apple['pos']);
        $this->assertContains($plan['ai_status'], ['ok', 'failed', 'off']);
        $probes = array_values(array_filter($plan['items'], fn ($item) => ! empty($item['probe'])));
        if (count($probes) >= 3) {
            $this->assertTrue(collect($probes)->contains(fn ($item) => ($item['kind'] ?? '') === 'sentence'));
        }
    }

    public function test_wrong_answer_returns_coach_tip_without_reward(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $result = $service->answer($child, 'apple', '香蕉');
        $this->assertFalse($result['correct']);
        $this->assertSame(0, $result['coins']);
        $this->assertNotSame('', $result['coach_tip']);
        $this->assertDatabaseHas('english_ai_records', [
            'child_id' => $child->id,
            'purpose' => 'coach_tips',
        ]);
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
