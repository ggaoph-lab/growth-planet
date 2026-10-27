<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Poetry\Models\PoetryAudioCache;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryCatalog;
use App\Modules\Poetry\Services\PoetryReviewScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PoetryPlanetTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_hub_has_three_modes_and_recite_works(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'jing-ye-si')->firstOrFail();

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry')
            ->assertOk()
            ->assertJsonPath('data.stats.required', 0)
            ->assertJsonCount(3, 'data.modes')
            ->assertJsonPath('data.modes.0.key', 'required')
            ->assertJsonPath('data.modes.1.key', 'recommend')
            ->assertJsonPath('data.modes.2.key', 'challenge');

        $this->getJson('/api/v1/kid/poetry/recommend')
            ->assertOk()
            ->assertJsonPath('data.mode', 'recommend')
            ->assertJsonStructure(['data' => ['current' => ['id', 'title', 'reason']]]);

        $show = $this->getJson('/api/v1/kid/poetry/poems/'.$poem->id)
            ->assertOk()
            ->assertJsonPath('data.poem.title', '静夜思')
            ->assertJsonPath('data.image.status', 'ready')
            ->assertJsonPath('data.image.provider', 'mock')
            ->assertJsonStructure(['data' => ['line_meanings', 'voice_available', 'story_zh']]);
        $poemJson = $show->json('data.image.url');
        $this->assertIsString($poemJson);
        $this->assertSame('/poetry-art/jing-ye-si.jpg', $poemJson);
        $meanings = $show->json('data.line_meanings');
        $this->assertCount(4, $meanings);
        $this->assertStringContainsString('月光', $meanings[0]);
        $this->assertGreaterThanOrEqual(28, mb_strlen($meanings[0]));
        $this->assertStringNotContainsString('慢慢想画面', $meanings[0]);

        $goose = PoetryPoem::query()->where('external_id', 'yong-e')->firstOrFail();
        $gooseUrl = $this->getJson('/api/v1/kid/poetry/poems/'.$goose->id)
            ->assertOk()
            ->json('data.image.url');
        $this->assertSame('/poetry-art/yong-e.jpg', $gooseUrl);
        $this->assertNotSame($poemJson, $gooseUrl);

        $this->postJson('/api/v1/kid/poetry/poems/'.$goose->id.'/recite', [
            'transcript' => '鹅鹅鹅曲项向天歌白毛浮绿水红掌拨清波',
            'mode' => 'read_aloud',
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.reward.points', 2);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/poetry/poems/'.$poem->id.'/read-aloud')
            ->assertOk()
            ->assertJsonPath('data.already', false);

        $this->postJson('/api/v1/kid/poetry/poems/'.$poem->id.'/recite', [
            'transcript' => '床前明月光疑是地上霜举头望明月低头思故乡',
            'mode' => 'recite',
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.reward.points', 5);

        $this->assertDatabaseHas('poetry_review_plans', [
            'child_id' => $child->id,
            'poem_id' => $poem->id,
        ]);

        // Now challenge mode lists the mastered poem.
        $this->getJson('/api/v1/kid/poetry/challenge')
            ->assertOk()
            ->assertJsonPath('data.mode', 'challenge')
            ->assertJsonPath('data.poems.0.id', $poem->id);

        $this->postJson('/api/v1/kid/poetry/poems/'.$poem->id.'/recite', [
            'transcript' => '床前明月光疑是地上霜举头望明月低头思故乡',
            'mode' => 'challenge',
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.reward.points', 3);
    }

    public function test_parent_assigns_required_poem(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'chun-xiao')->firstOrFail();

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/poetry/assignments', [
                'child_id' => $child->id,
                'poem_id' => $poem->id,
                'due_date' => '2026-11-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.poem_id', $poem->id);

        $this->assertDatabaseHas('poetry_assignments', [
            'child_id' => $child->id,
            'poem_id' => $poem->id,
            'status' => 'active',
        ]);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/poetry')
            ->assertOk()
            ->assertJsonPath('data.assignments.0.poem.title', '春晓');
    }

    public function test_kid_required_list_includes_assignment(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'chun-xiao')->firstOrFail();

        DB::table('poetry_assignments')->insert([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'poem_id' => $poem->id,
            'assigned_by' => $parent->id,
            'due_date' => '2026-11-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry')
            ->assertOk()
            ->assertJsonPath('data.stats.required', 1);

        $this->getJson('/api/v1/kid/poetry/required')
            ->assertOk()
            ->assertJsonPath('data.poems.0.title', '春晓');
    }

    public function test_weekly_recommendation_recorded_once_per_week(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry/recommend')
            ->assertOk();

        $this->assertSame(1, DB::table('poetry_weekly_recommendations')->where('child_id', $child->id)->count());

        $this->getJson('/api/v1/kid/poetry/recommend')
            ->assertOk();

        $this->assertSame(1, DB::table('poetry_weekly_recommendations')->where('child_id', $child->id)->count());
    }

    public function test_review_scheduler_intervals(): void
    {
        $scheduler = app(PoetryReviewScheduler::class);
        $anchor = CarbonImmutable::parse('2026-10-27', 'Asia/Shanghai');

        $this->assertSame('2026-10-28', $scheduler->nextReviewDate($anchor, 0)?->toDateString());
        $this->assertSame('2026-11-11', $scheduler->nextReviewDate($anchor, 4)?->toDateString());
        $this->assertNull($scheduler->nextReviewDate($anchor, 5));
    }

    public function test_disabled_poetry_planet_blocks_kid_api(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(Settings::class)->set('planets.poetry_planet', false, $family->id);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PLANET_DISABLED');
    }

    public function test_poetry_audio_library_uses_elevenlabs_then_local_cache(): void
    {
        Storage::fake('public');
        config([
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'poetry.audio.prefer_elevenlabs' => true,
            'poetry.audio.model' => 'eleven_multilingual_v2',
            'poetry.audio.disk' => 'public',
        ]);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('p', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('tts.elevenlabs_api_key', 'test-elevenlabs-key', $family->id);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'jing-ye-si')->firstOrFail();

        $script = app(PoetryAudioService::class)->fullExpressiveScript($poem);
        $this->assertStringContainsString('静夜思', $script);
        $this->assertStringContainsString('唐朝', $script);
        $this->assertStringContainsString('李白', $script);
        $this->assertStringContainsString('床前明月光', $script);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
            ->assertOk()
            ->assertJsonPath('data.clip', 'full')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.provider', 'elevenlabs')
            ->assertJsonPath('data.cached', false);

        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $poem->id,
            'clip_key' => 'full',
            'provider' => 'elevenlabs',
            'status' => 'ready',
        ]);
        $row = PoetryAudioCache::query()->where('poem_id', $poem->id)->where('clip_key', 'full')->firstOrFail();
        Storage::disk('public')->assertExists($row->path);

        Http::fake(); // no more upstream calls
        $this->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.provider', 'elevenlabs');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio/warm', ['clips' => ['full']])
            ->assertOk()
            ->assertJsonPath('data.items.0.cached', true);
    }
}
