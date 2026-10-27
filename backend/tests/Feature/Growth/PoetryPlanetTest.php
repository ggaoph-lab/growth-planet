<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Poetry\Models\PoetryAudioCache;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Services\PoetryAudioLibrary;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryCatalog;
use App\Modules\Poetry\Services\PoetryReviewScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonStructure(['data' => [
                'line_meanings',
                'voice_available',
                'story_zh',
                'author_zh',
                'author_learned_poems',
            ]]);
        $poemJson = $show->json('data.image.url');
        $this->assertIsString($poemJson);
        $this->assertSame('/poetry-art/jing-ye-si.jpg', $poemJson);
        $this->assertSame([], $show->json('data.author_learned_poems'));
        $authorZh = $show->json('data.author_zh');
        $this->assertIsString($authorZh);
        $this->assertStringContainsString('李白', $authorZh);
        $this->assertStringContainsString('诗仙', $authorZh);
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

        // Author intro lists other poems by the same author that the child already learned.
        $sameAuthor = PoetryPoem::query()->where('external_id', 'qing-ping-yue-cun-ju')->firstOrFail();
        $this->getJson('/api/v1/kid/poetry/poems/'.$sameAuthor->id)
            ->assertOk()
            ->assertJsonPath('data.author_learned_poems.0.id', $poem->id)
            ->assertJsonPath('data.author_learned_poems.0.title', '静夜思');
    }

    public function test_parent_assigns_required_poem(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        config([
            'poetry.audio.prefer_elevenlabs' => true,
            'poetry.audio.model' => 'eleven_multilingual_v2',
        ]);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('p', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        app(Settings::class)->set('tts.elevenlabs_api_key', 'test-elevenlabs-key', $family->id);
        app(Settings::class)->set('poetry.tts_provider', 'elevenlabs', $family->id);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'chun-xiao')->firstOrFail();

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/poetry/assignments', [
                'child_id' => $child->id,
                'poem_id' => $poem->id,
                'due_date' => '2026-11-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.poem_id', $poem->id)
            ->assertJsonPath('data.audio_warming', true);

        $this->assertDatabaseHas('poetry_assignments', [
            'child_id' => $child->id,
            'poem_id' => $poem->id,
            'status' => 'active',
        ]);

        // afterResponse warm writes permanent library clips (full + author + lines + meanings).
        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $poem->id,
            'clip_key' => 'full',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $poem->id,
            'clip_key' => 'author',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $poem->id,
            'clip_key' => 'meaning-0',
            'status' => 'ready',
        ]);
        $library = app(PoetryAudioLibrary::class);
        $this->assertNotNull($library->absoluteMp3($poem, 'full'));
        $this->assertNotNull($library->absoluteMp3($poem, 'line-0'));
        $this->assertNotNull($library->absoluteMp3($poem, 'meaning-0'));

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

        config([
            'poetry.audio.prefer_elevenlabs' => true,
            'poetry.audio.model' => 'eleven_multilingual_v2',
        ]);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('p', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        app(Settings::class)->set('tts.elevenlabs_api_key', 'test-elevenlabs-key', $family->id);
        app(Settings::class)->set('poetry.tts_provider', 'elevenlabs', $family->id);

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry/recommend')
            ->assertOk();

        $this->assertSame(1, DB::table('poetry_weekly_recommendations')->where('child_id', $child->id)->count());

        $rec = DB::table('poetry_weekly_recommendations')->where('child_id', $child->id)->first();
        $this->assertNotNull($rec);
        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $rec->poem_id,
            'clip_key' => 'full',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $rec->poem_id,
            'clip_key' => 'meaning-0',
            'status' => 'ready',
        ]);

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
        config([
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'poetry.audio.prefer_elevenlabs' => true,
            'poetry.audio.model' => 'eleven_multilingual_v2',
        ]);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('p', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('tts.elevenlabs_api_key', 'test-elevenlabs-key', $family->id);
        app(Settings::class)->set('poetry.tts_provider', 'elevenlabs', $family->id);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'jing-ye-si')->firstOrFail();
        $library = app(PoetryAudioLibrary::class);
        $this->forgetLibraryClip($library, $poem, 'full');

        $script = app(PoetryAudioService::class)->fullExpressiveScript($poem);
        $this->assertStringContainsString('静夜思', $script);
        $this->assertStringContainsString('唐朝', $script);
        $this->assertStringContainsString('李白', $script);
        $this->assertStringContainsString('床前明月光', $script);
        $this->assertStringNotContainsString('……', $script);

        $meta = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
            ->assertOk()
            ->assertJsonPath('data.clip', 'full')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.provider', 'elevenlabs')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.url', '/api/v1/kid/poetry/poems/'.$poem->id.'/audio/file?clip=full');

        $this->assertDatabaseHas('poetry_audio_cache', [
            'poem_id' => $poem->id,
            'clip_key' => 'full',
            'provider' => 'elevenlabs',
            'status' => 'ready',
        ]);
        $absolute = $library->absoluteMp3($poem, 'full');
        $this->assertNotNull($absolute);
        $this->assertFileExists($absolute);

        // Stream through the API (nginx SPA host has no /storage mount).
        $this->get((string) $meta->json('data.url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

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

    public function test_poetry_audio_seed_library_serves_without_tts(): void
    {
        config([
            'services.elevenlabs.api_key' => '',
            'poetry.audio.prefer_elevenlabs' => true,
            'poetry.audio.voice_zh' => 'Xb7hH8MSUJpSbSDYk0k2',
            'poetry.audio.model' => 'eleven_multilingual_v2',
        ]);

        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        app(Settings::class)->set('poetry.tts_provider', 'elevenlabs', $family->id);
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'yong-e')->firstOrFail();

        $library = app(PoetryAudioLibrary::class);
        $key = $library->libraryKey($poem);
        $this->forgetLibraryClip($library, $poem, 'full');
        $seed = $library->seedPath($key, 'full');
        File::ensureDirectoryExists(dirname($seed));
        file_put_contents($seed, str_repeat('s', 2400));
        file_put_contents($library->metaPath($key, 'full', $library->seedDirectory()), json_encode([
            'provider' => 'elevenlabs',
            'voice' => 'Xb7hH8MSUJpSbSDYk0k2',
            'model' => 'eleven_multilingual_v2',
            'script_hash' => 'seed',
            'bytes' => 2400,
        ], JSON_THROW_ON_ERROR));

        Http::fake(); // must not call ElevenLabs

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.provider', 'elevenlabs');

        Http::assertNothingSent();
        $this->assertFileExists($library->runtimePath($key, 'full'));

        $this->forgetLibraryClip($library, $poem, 'full');
    }

    public function test_qwen_poetry_uses_official_request_and_family_cache(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('poetry.tts_api_key', 'test-poetry-key', $family->id);
        app(Settings::class)->set('poetry.tts_workspace_id', 'testworkspace', $family->id);
        app(Settings::class)->set('poetry.tts_rate_percent', 85, $family->id);
        app(Settings::class)->set('poetry.tts_style', 'gentle', $family->id);
        Http::fake([
            'testworkspace.cn-beijing.maas.aliyuncs.com/*' => Http::response([
                'output' => ['audio' => ['url' => 'https://test.oss-cn-beijing.aliyuncs.com/poem.mp3']],
            ]),
            'test.oss-cn-beijing.aliyuncs.com/*' => Http::response(str_repeat('q', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'jing-ye-si')->firstOrFail();
        $clip = 'full--family-'.$family->id;
        $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, $clip);
        try {
            $this->actingAsKid($child, $device, $token)
                ->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
                ->assertOk()->assertJsonPath('data.provider', 'qwen-poetry')->assertJsonPath('data.clip', 'full');
            Http::assertSent(fn ($request) => str_contains($request->url(), 'SpeechSynthesizer')
                && $request->hasHeader('Authorization', 'Bearer test-poetry-key')
                && $request['model'] === 'qwen-audio-3.1-tts-flash'
                && $request['input']['voice'] === 'wenhuaiqing_v3.1'
                && $request['input']['rate'] === 0.85
                && str_contains($request['input']['instruction'], '根据诗名和诗句的意思')
                && str_contains($request['input']['instruction'], '每两句之后稍长停')
                && str_contains($request['input']['instruction'], '五言通常二三字')
                && str_contains($request['input']['instruction'], '多音字必须按词义')
                && str_contains($request['input']['instruction'], '还以为'));
            app(\App\Modules\Poetry\Services\QwenPoetryTts::class)->synthesize(
                '床前明月光。', 'test-poetry-key', 'testworkspace', 'cn-beijing', 'wenhuaiqing_v3.1', 0.85, 'gentle', 'line-0', '静夜思', '李白', '床前明月光 疑是地上霜',
            );
            Http::assertSent(fn ($request) => str_contains($request->url(), 'SpeechSynthesizer')
                && str_contains($request['input']['instruction'], '示范这一句古诗')
                && str_contains($request['input']['instruction'], '这句出自《静夜思》，作者李白')
                && str_contains($request['input']['instruction'], '全诗参考：床前明月光 疑是地上霜')
                && str_contains($request['input']['instruction'], '只朗读输入的诗句')
                && str_contains($request['input']['instruction'], '多音字必须按词义')
                && $request['input']['text'] === '床前明月光。'
                && ! str_contains($request['input']['instruction'], '题目、朝代和作者'));
            $authorClip = 'author--family-'.$family->id;
            $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, $authorClip);
            $authorMeta = $this->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=author')
                ->assertOk()
                ->assertJsonPath('data.provider', 'qwen-poetry')
                ->assertJsonPath('data.clip', 'author');
            $this->assertStringContainsString('李白', (string) $authorMeta->json('data.script'));
            Http::assertSent(fn ($request) => str_contains($request->url(), 'SpeechSynthesizer')
                && str_contains($request['input']['instruction'], '介绍这位诗人')
                && str_contains($request['input']['instruction'], '只朗读输入的作者简介')
                && str_contains((string) $request['input']['text'], '李白'));
            $this->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
                ->assertOk()->assertJsonPath('data.cached', true);
            $this->get('/api/v1/kid/poetry/poems/'.$poem->id.'/audio/file?clip=full')
                ->assertOk()->assertHeader('Content-Type', 'audio/mpeg')
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame(2, app(PoetryAudioService::class)->clearFamilyCache($family->id)['clips']);
            $this->assertDatabaseMissing('poetry_audio_cache', ['poem_id' => $poem->id, 'clip_key' => $clip]);
        } finally {
            $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, $clip);
            $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, 'author--family-'.$family->id);
        }
    }

    public function test_qwen_uses_api_key_without_workspace_and_keeps_narrator(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('tts.elevenlabs_api_key', 'existing-eleven-key', $family->id);
        app(Settings::class)->set('poetry.tts_api_key', 'qwen-key', $family->id);
        config(['poetry.audio.qwen_workspace_id' => '']);
        Http::fake([
            'dashscope.aliyuncs.com/*' => Http::response([
                'output' => ['audio' => ['url' => 'https://test.oss-cn-beijing.aliyuncs.com/poem.mp3']],
            ]),
            'test.oss-cn-beijing.aliyuncs.com/*' => Http::response(str_repeat('q', 2400), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        app(PoetryCatalog::class)->ensureBuiltinGrade1();
        $poem = PoetryPoem::query()->where('external_id', 'jing-ye-si')->firstOrFail();
        $clip = 'full--family-'.$family->id;
        $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, $clip);
        try {
            $this->actingAsKid($child, $device, $token)
                ->getJson('/api/v1/kid/poetry/poems/'.$poem->id.'/audio?clip=full')
                ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.provider', 'qwen-poetry');
            Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://dashscope.aliyuncs.com/api/v1/services/audio/tts/SpeechSynthesizer'));
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elevenlabs'));
        } finally {
            $this->forgetLibraryClip(app(PoetryAudioLibrary::class), $poem, $clip);
        }
    }

    public function test_parent_poetry_tts_settings_encrypt_key_and_do_not_replace_elevenlabs(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        app(Settings::class)->set('tts.elevenlabs_api_key', 'keep-elevenlabs', $family->id);
        $this->actingAsParent($parent)->putJson('/api/v1/parent/poetry/settings', [
            'tts_provider' => 'qwen', 'tts_api_key' => 'poetry-secret',
            'tts_workspace_id' => 'workspace123', 'tts_rate_percent' => 85,
            'tts_style' => 'gentle', 'follow_pause_ms' => 1600,
        ])->assertOk()->assertJsonPath('data.tts_key_configured', true)
            ->assertJsonPath('data.follow_pause_ms', 1600)
            ->assertDontSee('poetry-secret');
        $stored = DB::table('system_settings')->where('family_id', $family->id)
            ->where('key', 'poetry.tts_api_key')->value('value');
        $this->assertStringNotContainsString('poetry-secret', (string) $stored);
        $this->assertSame('keep-elevenlabs', app(Settings::class)->get('tts.elevenlabs_api_key', $family->id));
        $this->putJson('/api/v1/parent/poetry/settings', ['tts_rate_percent' => 95])->assertOk();
        $this->assertSame('poetry-secret', app(Settings::class)->get('poetry.tts_api_key', $family->id));
    }

    private function forgetLibraryClip(PoetryAudioLibrary $library, PoetryPoem $poem, string $clip): void
    {
        $key = $library->libraryKey($poem);
        foreach ([$library->runtimeDirectory(), $library->seedDirectory()] as $root) {
            @unlink($root.'/'.$key.'/'.$clip.'.mp3');
            @unlink($library->metaPath($key, $clip, $root));
            @rmdir($root.'/'.$key);
        }
        PoetryAudioCache::query()->where('poem_id', $poem->id)->where('clip_key', $clip)->delete();
    }
}
