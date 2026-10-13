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

        $service->answer($child, 'apple', '苹果');
        $this->assertSame(1, DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
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
        $plan = $service->plan($child);
        $this->assertTrue(collect($plan['items'])->contains(fn ($item) => ($item['text'] ?? '') === 'tiger'));
        $chatgpt = DB::table('english_ai_records')
            ->where('business_date', '2026-10-14')
            ->where('purpose', 'vocabulary_probe')
            ->first();
        $this->assertSame('openai', $chatgpt->provider);
        $this->assertContains($chatgpt->status, ['ai', 'mixed']);
        $this->assertStringContainsString('apple', (string) $chatgpt->profile_snapshot);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.test') && str_contains($request->body(), 'apple'));

        config([
            'services.qwen.api_key' => 'sk-qwen',
            'services.qwen.base_url' => 'https://qwen.test/compatible-mode/v1',
            'services.qwen.model' => 'qwen-turbo',
        ]);
        app(Settings::class)->set('ai.provider', 'qwen', $family->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'));
        $service->plan($child);

        $qwen = DB::table('english_ai_records')
            ->where('business_date', '2026-10-15')
            ->where('purpose', 'vocabulary_probe')
            ->first();
        $this->assertSame('qwen', $qwen->provider);
        $this->assertStringContainsString('apple', (string) $qwen->profile_snapshot);
        $this->assertSame(1, DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->value('estimated_size'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'qwen.test') && str_contains($request->body(), 'apple'));
    }
}
