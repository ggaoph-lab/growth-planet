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
        config(['services.tts.prefer_gemini' => false]);
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

    public function test_english_lines_use_an_english_neural_voice(): void
    {
        config([
            'services.tts.prefer_gemini' => false,
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

    public function test_falls_back_to_gemini_tts_when_edge_fails_and_prefer_gemini(): void
    {
        config([
            'services.tts.prefer_gemini' => true,
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.tts_model' => 'gemini-2.5-flash-preview-tts',
            'services.gemini.tts_voice_en' => 'Kore',
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $pcm = str_repeat("\0\0", 240); // 10ms of silence at 24kHz mono 16-bit
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'audio/L16;codec=pcm;rate=24000',
                                'data' => base64_encode($pcm),
                            ],
                        ]],
                    ],
                ]],
            ]),
        ]);
        // Interactive path always tries Edge first; Gemini is only the fallback.
        $this->mock(EdgeTtsClient::class, function ($mock): void {
            $mock->shouldReceive('synthesize')->once()->andReturn(null);
        });

        $response = $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/kid/tts', ['text' => 'I like apples.', 'lang' => 'en-US'])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.provider', 'gemini_tts')
            ->assertJsonPath('data.mime', 'audio/wav');

        $wav = base64_decode((string) $response->json('data.audio_base64'), true);
        $this->assertNotFalse($wav);
        $this->assertStringStartsWith('RIFF', $wav);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gemini-2.5-flash-preview-tts:generateContent')
                && ($request['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? null) === 'Kore'
                && ($request['contents'][0]['parts'][0]['text'] ?? null) === 'I like apples.';
        });
    }
}
