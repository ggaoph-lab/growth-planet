<?php

namespace Tests\Feature\Growth;

use App\Modules\Task\Services\EdgeTtsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KidTtsTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_lines_use_an_english_neural_voice(): void
    {
        config([
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
}
