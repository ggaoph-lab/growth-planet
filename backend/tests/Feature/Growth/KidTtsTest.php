<?php

namespace Tests\Feature\Growth;

use App\Modules\Task\Services\EdgeTtsClient;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class KidTtsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefetch_quota_does_not_block_tapped_playback(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->mock(QwenTtsService::class, function ($mock): void {
            $mock->shouldReceive('synthesize')->once()->andReturn(['audio_base64' => 'YQ==', 'mime' => 'audio/mpeg', 'provider' => 'edge_tts']);
            $mock->shouldReceive('qwenConfigured')->andReturn(false);
        });
        for ($i = 0; $i < 40; $i++) {
            RateLimiter::hit('kid-tts-warm:'.$child->id, 60);
        }
        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => 'Hello.', 'lang' => 'en-US'])->assertOk();
        $this->postJson('/api/v1/kid/tts/warm', ['texts' => ['Hello.'], 'lang' => 'en-US'])->assertStatus(429);
    }

    public function test_morning_audio_is_reused_in_the_evening(): void
    {
        config([
            'services.tts.prefer_gemini' => false,
            'services.qwen.api_key' => '',
        ]);
        ['family' => $family] = $this->makeFamily();
        $audio = ['audio_base64' => base64_encode(str_repeat('a', 80)), 'mime' => 'audio/mpeg', 'provider' => 'edge_tts'];
        $this->mock(EdgeTtsClient::class, function ($mock) use ($audio): void {
            $mock->shouldReceive('synthesize')->once()->andReturn($audio);
        });
        $tts = app(QwenTtsService::class);
        $this->assertSame($audio, $tts->synthesize('Good morning.', $family->id, 'en-US'));
        $this->travel(14)->hours();
        $this->assertSame($audio, $tts->synthesize('Good morning.', $family->id, 'en-US'));
    }

    public function test_qwen_is_preferred_over_edge_when_configured(): void
    {
        config([
            'services.qwen.api_key' => 'test-qwen-key',
            'services.qwen.tts_model' => 'qwen3-tts-flash',
            'services.qwen.tts_voice' => 'Cherry',
            'services.qwen.tts_voice_en' => 'Jennifer',
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        Http::fake([
            'dashscope.aliyuncs.com/*' => Http::response([
                'output' => ['audio' => ['data' => base64_encode(str_repeat('q', 80))]],
            ], 200),
        ]);
        $this->mock(EdgeTtsClient::class, function ($mock): void {
            $mock->shouldReceive('synthesize')->never();
            $mock->shouldReceive('synthesizeMany')->never();
        });

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => 'I like apples.', 'lang' => 'en-US'])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.provider', 'qwen');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $data = $request->data();

            return ($data['input']['voice'] ?? null) === 'Jennifer'
                && ($data['input']['language_type'] ?? null) === 'English';
        });
    }

    public function test_warm_uses_same_qwen_priority_as_speak(): void
    {
        config([
            'services.qwen.api_key' => 'test-qwen-key',
            'services.qwen.tts_model' => 'qwen3-tts-flash',
            'services.qwen.tts_voice' => 'Cherry',
        ]);
        ['family' => $family] = $this->makeFamily();

        Http::fake([
            'dashscope.aliyuncs.com/*' => Http::response([
                'output' => ['audio' => ['data' => base64_encode(str_repeat('w', 80))]],
            ], 200),
        ]);
        $this->mock(EdgeTtsClient::class, function ($mock): void {
            $mock->shouldReceive('synthesize')->never();
            $mock->shouldReceive('synthesizeMany')->never();
        });

        $items = app(QwenTtsService::class)->warm(['Hello there.'], 'en-US', $family->id);
        $this->assertCount(1, $items);
        $this->assertSame('qwen', $items[0]['provider']);
        $this->assertNotEmpty($items[0]['audio_base64']);
    }

    public function test_english_lines_use_an_english_neural_voice(): void
    {
        config([
            'services.tts.prefer_gemini' => false,
            'services.qwen.api_key' => '',
            'services.tts.edge_voice' => 'zh-CN-XiaoxiaoNeural',
            'services.tts.edge_voice_en' => 'en-US-JennyNeural',
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $audio = [
            'audio_base64' => base64_encode(str_repeat('a', 80)),
            'mime' => 'audio/mpeg',
            'provider' => 'edge_tts',
        ];
        $this->mock(EdgeTtsClient::class, function ($mock) use ($audio): void {
            $mock->shouldReceive('synthesize')
                ->once()
                ->with('I like apples.', 'en-US-JennyNeural')
                ->andReturn($audio);
        });

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => 'I like apples.', 'lang' => 'en-US'])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.provider', 'edge_tts');
    }

    public function test_task_read_aloud_keeps_the_chinese_voice(): void
    {
        config([
            'services.tts.prefer_gemini' => false,
            'services.qwen.api_key' => '',
            'services.tts.edge_voice' => 'zh-CN-XiaoxiaoNeural',
            'services.tts.edge_voice_en' => 'en-US-JennyNeural',
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $audio = [
            'audio_base64' => base64_encode(str_repeat('b', 80)),
            'mime' => 'audio/mpeg',
            'provider' => 'edge_tts',
        ];
        $this->mock(EdgeTtsClient::class, function ($mock) use ($audio): void {
            $mock->shouldReceive('synthesize')
                ->once()
                ->with('整理书包', 'zh-CN-XiaoxiaoNeural')
                ->andReturn($audio);
        });

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => '整理书包'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'edge_tts');
    }

    public function test_does_not_call_gemini_tts_when_edge_fails(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.qwen.api_key' => '',
            'services.tts.prefer_gemini' => true,
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response(['error' => 'unused'], 500);
            }
            if (str_contains($request->url(), 'translate.google.com')) {
                return Http::response(str_repeat('a', 128), 200, ['Content-Type' => 'audio/mpeg']);
            }

            return Http::response('unexpected', 404);
        });
        $this->mock(EdgeTtsClient::class, function ($mock): void {
            $mock->shouldReceive('synthesize')->once()->andReturn(null);
        });

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => 'I like apples.', 'lang' => 'en-US'])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.provider', 'google_tts');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }
}
