<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_update_default_locale(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->patchJson('/api/v1/parent/family', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_parent_can_add_and_remove_another_parent(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/parents', [
                'display_name' => '爸爸',
                'username' => 'baba',
                'password' => 'another-long-password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'baba')
            ->assertJsonPath('data.display_name', '爸爸');

        $this->assertTrue(Hash::check('another-long-password', User::query()->where('username', 'baba')->firstOrFail()->password));

        $other = FamilyMember::query()
            ->where('family_id', $family->id)
            ->where('role', MemberRole::Parent)
            ->where('display_name', '爸爸')
            ->firstOrFail();

        $this->actingAsParent($parent, sudo: true)
            ->deleteJson('/api/v1/parent/parents/'.$other->id)
            ->assertOk();

        $other->refresh()->load('user');
        $this->assertSame(MemberStatus::Disabled, $other->status);
        $this->assertNull($other->user?->username);
    }

    public function test_cannot_delete_self_or_last_parent(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $memberId = $parent->member->id;

        $this->actingAsParent($parent, sudo: true)
            ->deleteJson('/api/v1/parent/parents/'.$memberId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_parent_can_change_own_password(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/parents/me/password', [
                'current_password' => self::PARENT_PASSWORD,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $parent->fresh()->password));
    }

    public function test_ai_settings_store_encrypted_family_keys(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config(['services.gemini.api_key' => '', 'services.qwen.api_key' => '']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'provider' => 'gemini',
                'gemini_api_key' => 'AIza-family-secret-key',
                'gemini_model' => 'gemini-2.5-flash',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'gemini')
            ->assertJsonPath('data.gemini.configured', true)
            ->assertJsonPath('data.gemini.source', 'family')
            ->assertJsonMissingPath('data.gemini.api_key');

        $stored = app(Settings::class)->get('ai.gemini_api_key', $family->id);
        $this->assertSame('AIza-family-secret-key', $stored);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.gemini.configured', true)
            ->assertJsonPath('data.openai.configured', false)
            ->assertJsonMissingPath('data.gemini.api_key');
    }

    public function test_ai_settings_store_chatgpt_key(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config(['services.openai.api_key' => '']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'provider' => 'openai',
                'openai_api_key' => 'sk-family-chatgpt',
                'openai_model' => 'gpt-4o-mini',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.openai.configured', true)
            ->assertJsonPath('data.openai.source', 'family')
            ->assertJsonMissingPath('data.openai.api_key');

        $this->assertSame('sk-family-chatgpt', app(Settings::class)->get('ai.openai_api_key', $family->id));
    }

    public function test_tts_settings_are_separate_from_content_ai_qwen_key(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config(['services.qwen.api_key' => '', 'services.qwen.tts_voice' => 'Cherry', 'services.qwen.tts_voice_en' => 'Jennifer']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'provider' => 'qwen',
                'qwen_api_key' => 'sk-content-qwen',
                'tts_provider' => 'qwen',
                'tts_qwen_api_key' => 'sk-tts-only',
                'tts_voice' => 'Serena',
                'tts_voice_en' => 'Aiden',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'qwen')
            ->assertJsonPath('data.qwen.source', 'family')
            ->assertJsonPath('data.tts.provider', 'qwen')
            ->assertJsonPath('data.tts.source', 'tts')
            ->assertJsonPath('data.tts.voice', 'Serena')
            ->assertJsonPath('data.tts.voice_en', 'Aiden')
            ->assertJsonPath('data.tts.voices.en.0.id', 'Jennifer')
            ->assertJsonPath('data.tts.voices.zh.0.id', 'Cherry');
        $voices = $this->actingAsParent($parent)->getJson('/api/v1/parent/ai-settings')->json('data.tts.voices');
        $this->assertGreaterThanOrEqual(30, count($voices['zh'] ?? []));
        $this->assertGreaterThanOrEqual(20, count($voices['en'] ?? []));

        $settings = app(Settings::class);
        $this->assertSame('sk-content-qwen', $settings->get('ai.qwen_api_key', $family->id));
        $this->assertSame('sk-tts-only', $settings->get('tts.qwen_api_key', $family->id));

        $cfg = app(\App\Modules\Task\Services\FamilyAiConfig::class)->resolve($family->id);
        $this->assertSame('sk-content-qwen', $cfg['qwen_api_key']);
        $this->assertSame('sk-tts-only', $cfg['tts_qwen_api_key']);
        $this->assertSame('tts', $cfg['tts_qwen_source']);
    }

    public function test_elevenlabs_tts_key_can_be_saved_and_cleared(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config(['services.elevenlabs.api_key' => '']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'tts_provider' => 'elevenlabs',
                'tts_elevenlabs_api_key' => 'el-family-key',
            ])
            ->assertOk()
            ->assertJsonPath('data.tts.provider', 'elevenlabs')
            ->assertJsonPath('data.tts.elevenlabs.configured', true)
            ->assertJsonPath('data.tts.elevenlabs.source', 'family');

        $this->assertSame('el-family-key', app(Settings::class)->get('tts.elevenlabs_api_key', $family->id));

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'clear_tts_elevenlabs_api_key' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.tts.elevenlabs.configured', false)
            ->assertJsonPath('data.tts.elevenlabs.source', 'none');
    }

    public function test_tts_preview_uses_selected_voice_without_saving(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config([
            'services.qwen.api_key' => 'sk-preview',
            'services.qwen.tts_model' => 'qwen3-tts-flash',
            'services.qwen.tts_voice_en' => 'Jennifer',
        ]);
        app(Settings::class)->set('tts.voice_en', 'Jennifer', $family->id, $parent->id);

        \Illuminate\Support\Facades\Http::fake([
            'dashscope.aliyuncs.com/*' => \Illuminate\Support\Facades\Http::response([
                'output' => ['audio' => ['data' => base64_encode(str_repeat('p', 80))]],
            ], 200),
        ]);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-settings/tts-preview', [
                'voice' => 'Bella',
                'lang' => 'en-US',
            ])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.provider', 'qwen')
            ->assertJsonPath('data.voice', 'Bella');

        \Illuminate\Support\Facades\Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $data = $request->data();

            return ($data['input']['voice'] ?? null) === 'Bella'
                && ($data['input']['language_type'] ?? null) === 'English';
        });

        $this->assertSame('Jennifer', app(Settings::class)->get('tts.voice_en', $family->id));
    }

    public function test_ai_settings_store_openai_compatible_relay_base_url(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config([
            'services.openai.api_key' => '',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'provider' => 'openai',
                'openai_api_key' => 'ak-relay-key',
                'openai_model' => 'claude-sonnet-4',
                'openai_base_url' => 'https://ai.akile.ai',
            ])
            ->assertOk()
            ->assertJsonPath('data.openai.configured', true)
            ->assertJsonPath('data.openai.base_url', 'https://ai.akile.ai/v1')
            ->assertJsonPath('data.openai.base_url_customized', true)
            ->assertJsonPath('data.openai.model', 'claude-sonnet-4');

        $this->assertSame('https://ai.akile.ai/v1', app(Settings::class)->get('ai.openai_base_url', $family->id));
        $cfg = app(\App\Modules\Task\Services\FamilyAiConfig::class)->resolve($family->id);
        $this->assertSame('https://ai.akile.ai/v1', $cfg['openai_base_url']);
    }

    public function test_ai_model_list_defaults_to_the_newest_chat_model(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        config([
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.qwen.api_key' => '',
            'services.qwen.base_url' => 'https://dashscope.example/compatible-mode/v1',
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://openai.example/v1',
        ]);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.gemini.model_customized', false);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, ':generateContent')) {
                return Http::response([
                    'candidates' => [
                        ['content' => ['parts' => [['text' => 'ok']]]],
                    ],
                ]);
            }
            if (str_contains($url, '/chat/completions')) {
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => 'ok']],
                    ],
                ]);
            }
            if (str_contains($url, 'generativelanguage.example')) {
                return Http::response([
                    'models' => [
                        ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
                        ['name' => 'models/gemini-3.8-flash', 'displayName' => 'Gemini 3.8 Flash', 'supportedGenerationMethods' => ['generateContent']],
                        ['name' => 'models/gemini-3.8-pro', 'displayName' => 'Gemini 3.8 Pro', 'supportedGenerationMethods' => ['generateContent']],
                        ['name' => 'models/gemini-3.8-flash-lite', 'displayName' => 'Gemini 3.8 Flash Lite', 'supportedGenerationMethods' => ['generateContent']],
                        ['name' => 'models/gemini-3.8-flash-tts', 'displayName' => 'TTS', 'supportedGenerationMethods' => ['generateContent']],
                        ['name' => 'models/gemini-embedding-001', 'displayName' => 'Embed', 'supportedGenerationMethods' => ['embedContent']],
                    ],
                ]);
            }
            if (str_contains($url, 'dashscope.example')) {
                return Http::response([
                    'data' => [
                        ['id' => 'qwen-turbo', 'created' => 0],
                        ['id' => 'qwen3-max', 'created' => 0],
                        ['id' => 'qwen3.8-max', 'created' => 0],
                        ['id' => 'qwen3-tts-flash', 'created' => 0],
                        ['id' => 'text-embedding-v3', 'created' => 0],
                    ],
                ]);
            }

            return Http::response([
                'data' => [
                    ['id' => 'gpt-4o-mini', 'created' => 100],
                    ['id' => 'gpt-5.4', 'created' => 800],
                    ['id' => 'gpt-4o-mini-tts', 'created' => 900],
                    ['id' => 'text-embedding-3-small', 'created' => 950],
                    ['id' => 'whisper-1', 'created' => 50],
                ],
            ]);
        });

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-models', ['provider' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('data.latest', 'gemini-3.8-pro')
            ->assertJsonPath('data.models.0.id', 'gemini-3.8-pro')
            ->assertJsonPath('data.probed', true)
            ->assertJsonMissing(['id' => 'gemini-3.8-flash-tts'])
            ->assertJsonMissing(['id' => 'gemini-embedding-001']);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-models', [
                'provider' => 'qwen',
                'api_key' => 'sk-draft-qwen',
            ])
            ->assertOk()
            ->assertJsonPath('data.latest', 'qwen3.8-max')
            ->assertJsonPath('data.probed', true)
            ->assertJsonMissing(['id' => 'qwen3-tts-flash']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-draft-qwen'));

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-models', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('data.latest', 'gpt-5.4')
            ->assertJsonPath('data.probed', true)
            ->assertJsonMissing(['id' => 'gpt-4o-mini-tts'])
            ->assertJsonMissing(['id' => 'whisper-1']);
    }

    public function test_ai_model_list_hides_models_that_fail_availability_probe(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        config([
            'services.gemini.api_key' => 'gemini-test',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, 'models/gemini-2.5-flash:generateContent')) {
                return Http::response([
                    'error' => [
                        'code' => 404,
                        'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
                        'status' => 'NOT_FOUND',
                    ],
                ], 404);
            }
            if (str_contains($url, ':generateContent')) {
                return Http::response([
                    'candidates' => [
                        ['content' => ['parts' => [['text' => 'ok']]]],
                    ],
                ]);
            }

            return Http::response([
                'models' => [
                    ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-3.1-flash-lite', 'displayName' => 'Gemini 3.1 Flash Lite', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-3.5-flash', 'displayName' => 'Gemini 3.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
                ],
            ]);
        });

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-models', ['provider' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('data.probed', true)
            ->assertJsonPath('data.latest', 'gemini-3.5-flash')
            ->assertJsonMissing(['id' => 'gemini-2.5-flash'])
            ->assertJsonFragment(['id' => 'gemini-3.1-flash-lite'])
            ->assertJsonFragment(['id' => 'gemini-3.5-flash']);
    }

    public function test_ai_model_list_reports_a_missing_key(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        config([
            'services.gemini.api_key' => '',
            'services.qwen.api_key' => '',
            'services.openai.api_key' => '',
        ]);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/ai-models', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('data.models', [])
            ->assertJsonPath('data.latest', null)
            ->assertJsonPath('data.error', 'not_configured');
    }

    public function test_ai_settings_require_sudo_to_update(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/ai-settings', ['provider' => 'qwen'])
            ->assertStatus(423);
    }
}
