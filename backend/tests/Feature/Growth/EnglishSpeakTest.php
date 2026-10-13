<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishSpeakService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnglishSpeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_speak_prompt_and_local_score_without_ai(): void
    {
        Storage::fake('local');
        config([
            'services.gemini.api_key' => '',
            'services.qwen.api_key' => '',
            'services.openai.api_key' => '',
        ]);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $learning = app(EnglishLearningService::class);
        $speak = app(EnglishSpeakService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Shanghai'));

        $learning->answer($child, 'apple', '苹果');
        $learning->answer($child, 'banana', '香蕉');
        $learning->answer($child, 'like-apples', '我喜欢苹果。');

        $prompt = $speak->prompt($child);
        $this->assertTrue($prompt['available']);
        $this->assertSame('like-apples', $prompt['target']['key']);
        $this->assertNull($prompt['attempt']);

        $file = UploadedFile::fake()->create('speak.webm', 2, 'audio/webm');
        $attempt = $speak->submit($child, $file, 1200);
        $this->assertSame('local', $attempt['status']);
        $this->assertNotNull($attempt['score']);
        $this->assertNotSame('', $attempt['feedback']);
        $this->assertDatabaseHas('english_speak_attempts', [
            'child_id' => $child->id,
            'business_date' => '2026-10-13',
            'target_key' => 'like-apples',
        ]);
        $this->assertDatabaseHas('english_ai_records', [
            'child_id' => $child->id,
            'purpose' => 'speak_score',
            'business_date' => '2026-10-13',
        ]);

        $again = UploadedFile::fake()->create('speak2.webm', 2, 'audio/webm');
        $speak->submit($child, $again, 900);
        $this->assertSame(1, DB::table('english_speak_attempts')->where('child_id', $child->id)->count());

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/english/learning/speaks?child_id='.$child->id)
            ->assertOk()
            ->assertJsonPath('data.0.target_key', 'like-apples');

        $id = (int) DB::table('english_speak_attempts')->value('id');
        $this->actingAsParent($parent)
            ->get('/api/v1/parent/english/learning/speaks/'.$id.'/audio?child_id='.$child->id)
            ->assertOk();
    }

    public function test_prune_expired_speak_attempts(): void
    {
        Storage::fake('local');
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $path = 'english-speak/'.$family->id.'/'.$child->id.'/old.webm';
        Storage::disk('local')->put($path, 'audio');
        DB::table('english_speak_attempts')->insert([
            'child_id' => $child->id,
            'family_id' => $family->id,
            'business_date' => '2026-01-01',
            'target_key' => 'apple',
            'target_text' => 'apple',
            'target_meaning' => '苹果',
            'disk' => 'local',
            'path' => $path,
            'mime' => 'audio/webm',
            'bytes' => 5,
            'duration_ms' => 1000,
            'transcript' => null,
            'score' => 3,
            'feedback' => 'ok',
            'provider' => null,
            'model' => null,
            'status' => 'local',
            'expires_at' => now()->subDay(),
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        $count = app(EnglishSpeakService::class)->pruneExpired();
        $this->assertSame(1, $count);
        $this->assertDatabaseCount('english_speak_attempts', 0);
        Storage::disk('local')->assertMissing($path);
    }
}
