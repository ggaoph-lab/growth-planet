<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HomeworkImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.homework_llm.provider' => 'auto',
            'services.gemini.api_key' => '',
            'services.qwen.api_key' => '',
        ]);
    }

    public function test_parse_falls_back_to_rules_without_ai_keys(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $text = <<<'TEXT'
周日数学学习任务
1. 指读数学常用词：计算、得数、算式
2.指读数学书P29页题目，练习读题能力。
3. 进行口算卡片练习2遍
TEXT;

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', ['text' => $text, 'task_date' => '2026-10-12'])
            ->assertOk()
            ->assertJsonPath('data.parser', 'rules')
            ->assertJsonPath('data.subject', '数学')
            ->assertJsonPath('data.ai_configured', false)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.title', '指读数学常用词');
    }

    public function test_parse_uses_gemini_when_configured(): void
    {
        config([
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.gemini.model' => 'gemini-2.5-flash',
        ]);

        Http::fake([
            'generativelanguage.example/*' => Http::response([
                'modelVersion' => 'gemini-2.5-flash',
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'subject' => '数学',
                                'task_date' => '2026-10-12',
                                'title' => '周日数学学习任务',
                                'items' => [
                                    ['title' => '指读常用词', 'detail' => '计算、得数'],
                                    ['title' => '读题练习', 'detail' => 'P29'],
                                    ['title' => '口算卡片', 'detail' => '2遍'],
                                ],
                                'confidence' => 0.92,
                                'notes' => null,
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => "乱七八糟的老师通知\n数学：口算两遍顺便读词",
                'task_date' => '2026-10-12',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'gemini')
            ->assertJsonPath('data.ai_configured', true)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.title', '指读常用词');

        Http::assertSentCount(1);
    }

    public function test_parse_uses_qwen_when_configured(): void
    {
        config([
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
            'services.qwen.model' => 'qwen-turbo',
        ]);

        Http::fake([
            'dashscope.example/*' => Http::response([
                'model' => 'qwen-turbo',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'subject' => '数学',
                            'task_date' => '2026-10-12',
                            'title' => '周日数学学习任务',
                            'items' => [
                                ['title' => '指读常用词', 'detail' => '计算、得数'],
                                ['title' => '读题练习', 'detail' => 'P29'],
                                ['title' => '口算卡片', 'detail' => '2遍'],
                            ],
                            'confidence' => 0.9,
                            'notes' => null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => "乱七八糟的老师通知\n数学：口算两遍顺便读词",
                'task_date' => '2026-10-12',
                'provider' => 'qwen',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'qwen')
            ->assertJsonPath('data.ai_configured', true)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.title', '指读常用词');

        Http::assertSentCount(1);
    }

    public function test_auto_prefers_gemini_over_qwen(): void
    {
        config([
            'services.homework_llm.provider' => 'auto',
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.gemini.model' => 'gemini-2.5-flash',
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
        ]);

        Http::fake([
            'generativelanguage.example/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'subject' => '语文',
                                'task_date' => '2026-10-12',
                                'title' => '语文作业',
                                'items' => [['title' => '朗读课文', 'detail' => null]],
                                'confidence' => 0.88,
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
            ], 200),
            'dashscope.example/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => '语文：朗读课文一遍',
                'task_date' => '2026-10-12',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'gemini')
            ->assertJsonPath('data.subject', '语文');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.example'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dashscope.example'));
    }

    public function test_explicit_gemini_does_not_fall_back_to_qwen_on_http_error(): void
    {
        config([
            'services.homework_llm.provider' => 'auto',
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
        ]);

        Http::fake([
            'generativelanguage.example/*' => Http::response(['error' => 'quota'], 429),
            'dashscope.example/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => "英语作业\n1. 听读 Unit1",
                'task_date' => '2026-10-12',
                'provider' => 'gemini',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'rules')
            ->assertJsonPath('data.notes', 'Gemini 解析失败，已使用规则解析，请仔细核对');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.example'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dashscope.example'));
    }

    public function test_falls_back_to_qwen_only_when_gemini_times_out(): void
    {
        config([
            'services.homework_llm.provider' => 'gemini',
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generativelanguage.example')) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'subject' => '英语',
                            'task_date' => '2026-10-12',
                            'title' => '英语作业',
                            'items' => [['title' => '听读单词', 'detail' => 'Unit 1']],
                            'confidence' => 0.8,
                            'notes' => null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200);
        });

        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => '英语 Unit1 听读',
                'task_date' => '2026-10-12',
                'provider' => 'gemini',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'qwen')
            ->assertJsonPath('data.subject', '英语')
            ->assertJsonPath('data.notes', 'Gemini 连接超时，已改用千问');
    }

    public function test_auto_uses_family_default_qwen_without_calling_gemini(): void
    {
        config([
            'services.homework_llm.provider' => 'auto',
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
        ]);

        Http::fake([
            'generativelanguage.example/*' => Http::response(['error' => 'should not call'], 500),
            'dashscope.example/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'subject' => '语文',
                            'task_date' => '2026-10-12',
                            'title' => '语文作业',
                            'items' => [['title' => '朗读课文', 'detail' => null]],
                            'confidence' => 0.8,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        app(Settings::class)->set('ai.provider', 'qwen', $family->id);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => '语文：朗读课文一遍',
                'task_date' => '2026-10-12',
                'provider' => 'auto',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'qwen')
            ->assertJsonPath('data.subject', '语文');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.example'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dashscope.example'));
    }

    public function test_explicit_gemini_wins_over_family_default_qwen(): void
    {
        config([
            'services.homework_llm.provider' => 'qwen',
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.gemini.model' => 'gemini-2.5-flash',
            'services.qwen.api_key' => 'sk-test',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
        ]);

        Http::fake([
            'generativelanguage.example/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'subject' => '语文',
                                'task_date' => '2026-10-12',
                                'title' => '语文作业',
                                'items' => [['title' => '朗读课文', 'detail' => null]],
                                'confidence' => 0.88,
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
            ], 200),
            'dashscope.example/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        app(Settings::class)->set('ai.provider', 'qwen', $family->id);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/homework/parse', [
                'text' => '语文：朗读课文一遍',
                'task_date' => '2026-10-12',
                'provider' => 'gemini',
            ])
            ->assertOk()
            ->assertJsonPath('data.parser', 'gemini');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.example'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dashscope.example'));
    }

    public function test_confirm_creates_stepped_task_and_instance(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $response = $this->actingAsParent($parent)->postJson('/api/v1/parent/homework/confirm', [
            'child_id' => $child->id,
            'subject' => '数学',
            'task_date' => '2026-10-12',
            'title' => '周日数学学习任务',
            'items' => [
                ['title' => '指读常用词', 'detail' => '计算、得数', 'suggested_coins' => 3, 'suggested_xp' => 5],
                ['title' => '读题练习', 'detail' => null, 'suggested_coins' => 3, 'suggested_xp' => 5],
                ['title' => '口算卡片', 'detail' => '2遍', 'suggested_coins' => 3, 'suggested_xp' => 5],
            ],
            'xp_reward' => 15,
            'coin_reward' => 9,
            'requires_approval' => true,
            'with_parent' => true,
            'source_text' => "周日数学学习任务\n1. 指读…",
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.task.title', '周日数学学习任务')
            ->assertJsonPath('data.task.kind', 'standard')
            ->assertJsonPath('data.task.category', '数学')
            ->assertJsonPath('data.task.with_parent', true)
            ->assertJsonPath('data.task.xp_reward', 15)
            ->assertJsonPath('data.task.coin_reward', 9)
            ->assertJsonCount(3, 'data.task.children')
            ->assertJsonCount(3, 'data.instances');

        $instances = collect($response->json('data.instances'));
        $this->assertEqualsCanonicalizing(
            ['指读常用词：计算、得数', '读题练习', '口算卡片：2遍'],
            $instances->pluck('snapshot.title')->all(),
        );
        $this->assertTrue($instances->every(
            fn ($instance) => ($instance['snapshot']['group_title'] ?? null) === '周日数学学习任务'
                && ($instance['snapshot']['group_task_id'] ?? null) === $response->json('data.task.id')
                && ($instance['child_id'] ?? null) === $child->id
                && ($instance['status'] ?? null) === 'pending',
        ));
        $reading = $instances->firstWhere('snapshot.title', '指读常用词：计算、得数');
        $this->assertNotNull($reading);
        $this->assertSame(5, $reading['snapshot']['xp_reward'] ?? null);
        $this->assertSame(3, $reading['snapshot']['coin_reward'] ?? null);
        $this->assertTrue((bool) ($reading['snapshot']['with_parent'] ?? false));

        $taskDate = (string) $instances->first()['task_date'];
        $this->assertTrue(str_starts_with($taskDate, '2026-10-12'), 'task_date should be 2026-10-12, got '.$taskDate);
    }

    public function test_kid_cannot_parse_homework(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/parent/homework/parse', ['text' => '数学作业 1. 口算'])
            ->assertForbidden();
    }
}
