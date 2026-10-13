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
        $this->assertNotNull($gate->probeCard([
            'text' => 'bird',
            'meaning' => '鸟',
            'options' => ['鸟', '鱼', '树'],
            'emoji' => '🐦',
        ], [], 1));
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
