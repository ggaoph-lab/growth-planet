<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\English\Services\EnglishLearningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnglishVocabularyAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_vocabulary_record_survives_switching_from_chatgpt_to_qwen(): void
    {
        config([
            'services.gemini.api_key' => '',
            'services.qwen.api_key' => '',
            'services.openai.api_key' => '',
        ]);
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $card = $service->plan($child)['items'][0];
        $service->answer($child, $card['key'], $card['meaning']);
        $this->assertSame(1, DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
        $knownWord = (string) $card['text'];
        // Probes only appear after the daily new-word goal and fairy tale are done.
        for ($i = 0; $i < 10; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:ai-seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => '2026-10-13',
                    'due_date' => '2026-10-14',
                    'completed_date' => '2026-10-13',
                ],
            );
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');
        $service->plan($child);
        $probe = DB::table('english_ai_records')->where('purpose', 'vocabulary_probe')->first();
        $this->assertSame('local', $probe->status);
        $this->assertNull($probe->provider);

        config([
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.model' => 'gpt-4o-mini',
        ]);
        app(Settings::class)->set('ai.provider', 'openai', $family->id);
        Http::fake([
            'https://openai.test/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'items' => [[
                            'text' => 'tiger',
                            'meaning' => '老虎',
                            'emoji' => '🐯',
                            'options' => ['老虎', '狮子', '熊'],
                        ]],
                        'order' => [],
                        'tips' => [],
                        'summary' => '本周练习稳定。',
                        'focuses' => ['动物'],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
            'https://qwen.test/*' => Http::response([
                'model' => 'qwen-turbo',
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'items' => [[
                            'text' => 'lion',
                            'meaning' => '狮子',
                            'emoji' => '🦁',
                            'options' => ['狮子', '老虎', '熊'],
                        ]],
                        'order' => [],
                        'tips' => [],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Shanghai'));
        for ($i = 0; $i < 10; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:ai-seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => '2026-10-14',
                    'due_date' => '2026-10-15',
                    'completed_date' => '2026-10-14',
                ],
            );
        }
        $this->markEnglishFairyHeard($child, '2026-10-14');
        $plan = $service->plan($child);
        $this->assertTrue(collect($plan['items'])->contains(fn ($item) => ($item['text'] ?? '') === 'tiger'));
        $chatgpt = DB::table('english_ai_records')
            ->where('business_date', '2026-10-14')
            ->where('purpose', 'vocabulary_probe')
            ->first();
        $this->assertSame('openai', $chatgpt->provider);
        $this->assertContains($chatgpt->status, ['ai', 'mixed']);
        $this->assertStringContainsString($knownWord, (string) $chatgpt->profile_snapshot);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.test') && str_contains($request->body(), $knownWord));

        config([
            'services.qwen.api_key' => 'sk-qwen',
            'services.qwen.base_url' => 'https://qwen.test/compatible-mode/v1',
            'services.qwen.model' => 'qwen-turbo',
        ]);
        app(Settings::class)->set('ai.provider', 'qwen', $family->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'));
        for ($i = 0; $i < 10; $i++) {
            DB::table('english_learning_progress')->updateOrInsert(
                ['child_id' => $child->id, 'item_key' => 'word:ai-seed'.$i],
                [
                    'stage' => 1,
                    'introduced_date' => '2026-10-15',
                    'due_date' => '2026-10-16',
                    'completed_date' => '2026-10-15',
                ],
            );
        }
        $this->markEnglishFairyHeard($child, '2026-10-15');
        $service->plan($child);

        $qwen = DB::table('english_ai_records')
            ->where('business_date', '2026-10-15')
            ->where('purpose', 'vocabulary_probe')
            ->first();
        $this->assertSame('qwen', $qwen->provider);
        $this->assertStringContainsString($knownWord, (string) $qwen->profile_snapshot);
        $this->assertSame(1, DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'qwen.test') && str_contains($request->body(), $knownWord));
    }

    public function test_probe_refill_asks_ai_for_new_sentences_instead_of_ladder_repeats(): void
    {
        config([
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.model' => 'gpt-4o-mini',
        ]);
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        app(Settings::class)->set('ai.provider', 'openai', $family->id);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        // Skip AI daily-word generation so probe refill calls are easy to inspect.
        for ($i = 0; $i < 10; $i++) {
            DB::table('english_learning_progress')->insert([
                'child_id' => $child->id,
                'item_key' => 'word:ai-seed'.$i,
                'stage' => 1,
                'introduced_date' => '2026-10-13',
                'due_date' => '2026-10-14',
                'completed_date' => '2026-10-13',
            ]);
        }
        $this->markEnglishFairyHeard($child, '2026-10-13');
        DB::table('english_ai_records')->insert([
            'child_id' => $child->id,
            'purpose' => 'daily_word_pack',
            'business_date' => '2026-10-13',
            'profile_snapshot' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'items' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'local',
            'provider' => null,
            'model' => null,
            'created_at' => now(),
        ]);

        $probeCalls = 0;
        Http::fake(function ($request) use (&$probeCalls) {
            $body = $request->body();
            $isProbePrompt = str_contains($body, 'avoid_repeat')
                || str_contains($body, 'sentences_only')
                || str_contains($body, 'VERY easy English checks')
                || str_contains($body, 'gentle English checks')
                || str_contains($body, 'ONLY easy English short sentences')
                || str_contains($body, 'ONLY gentle English short sentences')
                || str_contains($body, 'growing vocabulary');
            if ($isProbePrompt) {
                $probeCalls++;
                $items = $probeCalls === 1
                    ? [
                        ['kind' => 'word', 'text' => 'pencil', 'meaning' => '铅笔', 'emoji' => '✏️', 'ipa' => '/ˈpensl/', 'pos' => 'n.', 'options' => ['铅笔', '橡皮', '书包']],
                        ['kind' => 'sentence', 'text' => 'I have a pencil.', 'meaning' => '我有一支铅笔。', 'emoji' => '✏️', 'options' => ['我有一支铅笔。', '我想喝水。', '这是猫。']],
                        ['kind' => 'word', 'text' => 'eraser', 'meaning' => '橡皮', 'emoji' => '🧽', 'ipa' => '/ɪˈreɪsər/', 'pos' => 'n.', 'options' => ['橡皮', '铅笔', '尺子']],
                        ['kind' => 'sentence', 'text' => 'The desk is clean.', 'meaning' => '桌子很干净。', 'emoji' => '🧹', 'options' => ['桌子很干净。', '我饿了。', '下雨了。']],
                    ]
                    : [
                        ['kind' => 'sentence', 'text' => 'Please open the door.', 'meaning' => '请开门。', 'emoji' => '🚪', 'options' => ['请开门。', '下雨了。', '我饿了。']],
                        ['kind' => 'sentence', 'text' => 'She likes milk.', 'meaning' => '她喜欢牛奶。', 'emoji' => '🥛', 'options' => ['她喜欢牛奶。', '他在跑步。', '这是书。']],
                        ['kind' => 'word', 'text' => 'jacket', 'meaning' => '夹克', 'emoji' => '🧥', 'ipa' => '/ˈdʒækɪt/', 'pos' => 'n.', 'options' => ['夹克', '帽子', '鞋子']],
                    ];
            } else {
                $items = [
                    ['kind' => 'word', 'text' => 'quiet', 'meaning' => '安静的', 'emoji' => '🤫', 'ipa' => '/ˈkwaɪət/', 'pos' => 'adj.', 'options' => ['安静的', '吵闹的', '勇敢的']],
                ];
            }

            return Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => json_encode([
                    'items' => $items,
                    'order' => [],
                    'tips' => [],
                ], JSON_UNESCAPED_UNICODE)]]],
            ]);
        });

        $first = $service->plan($child);
        $firstSentences = collect($first['items'])
            ->filter(fn ($item) => ($item['kind'] ?? '') === 'sentence')
            ->pluck('text')
            ->all();
        $this->assertContains('I have a pencil.', $firstSentences);

        foreach ($first['items'] as $card) {
            $answer = ! empty($card['spelling'])
                ? (string) ($card['answer'] ?? $card['text'])
                : (string) ($card['meaning'] ?? $card['options'][0] ?? '');
            $service->answer($child, $card['key'], $answer);
        }

        $second = $service->plan($child);
        $secondSentences = collect($second['items'])
            ->filter(fn ($item) => ($item['kind'] ?? '') === 'sentence')
            ->pluck('text')
            ->map(fn ($text) => (string) $text)
            ->values()
            ->all();
        $this->assertNotEmpty($secondSentences);
        $this->assertNotContains('I have a pencil.', $secondSentences);
        $this->assertTrue(
            collect($secondSentences)->contains(fn ($text) => in_array($text, ['Please open the door.', 'She likes milk.'], true)),
            'refill should surface AI sentences, not only ladder repeats',
        );
        $this->assertGreaterThanOrEqual(2, $probeCalls, 'refill should call AI again for new probe cards');
    }
}
