<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Task\Services\AiModelCatalog;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\QwenTtsService;
use App\Modules\Task\Services\QwenTtsVoiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiSettingsController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly FamilyAiConfig $aiConfig,
        private readonly AiModelCatalog $models,
        private readonly QwenTtsService $tts,
        private readonly QwenTtsVoiceCatalog $ttsVoices,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->aiConfig->present($this->context->familyId())]);
    }

    public function models(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['gemini', 'qwen', 'openai'])],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:512'],
            'preferred_model' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $userId = $this->context->user()->id;
        $throttleKey = 'ai-models:'.$userId;
        if (RateLimiter::tooManyAttempts($throttleKey, 20)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $provider = (string) $data['provider'];
        $cfg = $this->aiConfig->resolve($this->context->familyId());
        $draft = trim((string) ($data['api_key'] ?? ''));
        $apiKey = $draft !== '' ? $draft : (string) $cfg[$provider.'_api_key'];
        $draftBase = trim((string) ($data['base_url'] ?? ''));
        $baseUrl = match ($provider) {
            'gemini' => (string) config('services.gemini.base_url'),
            'openai' => $draftBase !== ''
                ? FamilyAiConfig::normalizeOpenAiBaseUrl($draftBase)
                : (string) $cfg['openai_base_url'],
            default => (string) config('services.qwen.base_url'),
        };
        $preferred = trim((string) ($data['preferred_model'] ?? ''));
        if ($preferred === '') {
            $preferred = (string) ($cfg[$provider.'_model'] ?? '');
        }

        return response()->json([
            'data' => $this->models->list($provider, $apiKey, $baseUrl, $preferred !== '' ? $preferred : null),
        ]);
    }

    public function previewTts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice' => ['required', 'string', 'max:64'],
            'lang' => ['required', 'string', 'in:zh-CN,en-US'],
            'text' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $userId = $this->context->user()->id;
        $throttleKey = 'tts-preview:'.$userId;
        if (RateLimiter::tooManyAttempts($throttleKey, 30)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $voice = trim((string) $data['voice']);
        if ($voice === '' || (! $this->ttsVoices->isKnown($voice) && ! preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,63}$/', $voice))) {
            throw ValidationException::withMessages([
                'voice' => BiMsg::of('请选择有效的音色', 'Please choose a valid voice'),
            ]);
        }

        $familyId = $this->context->familyId();
        $result = $this->tts->preview(
            $familyId,
            $voice,
            (string) $data['lang'],
            isset($data['text']) ? (string) $data['text'] : null,
        );

        if ($result === null) {
            return response()->json([
                'data' => [
                    'available' => false,
                    'audio_base64' => null,
                    'mime' => null,
                    'provider' => null,
                    'qwen_configured' => $this->tts->qwenConfigured($familyId),
                    'hint' => $this->tts->qwenConfigured($familyId)
                        ? 'tts_upstream_failed'
                        : 'configure_dashscope_key',
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'available' => true,
                'audio_base64' => $result['audio_base64'],
                'mime' => $result['mime'],
                'provider' => $result['provider'],
                'qwen_configured' => $this->tts->qwenConfigured($familyId),
                'voice' => $voice,
                'lang' => $data['lang'],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['sometimes', 'string', Rule::in(['auto', 'gemini', 'qwen', 'openai', 'rules'])],
            'gemini_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'gemini_model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'qwen_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'qwen_model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'openai_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'openai_model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'openai_base_url' => ['sometimes', 'nullable', 'string', 'max:512'],
            'clear_gemini_api_key' => ['sometimes', 'boolean'],
            'clear_qwen_api_key' => ['sometimes', 'boolean'],
            'clear_openai_api_key' => ['sometimes', 'boolean'],
            'clear_openai_base_url' => ['sometimes', 'boolean'],
            'tts_provider' => ['sometimes', 'string', Rule::in(['auto', 'qwen', 'edge'])],
            'tts_qwen_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'tts_voice' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tts_voice_en' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clear_tts_qwen_api_key' => ['sometimes', 'boolean'],
        ]);

        $familyId = $this->context->familyId();
        $actorId = $this->context->user()->id;
        $changes = [];

        if (isset($data['provider'])) {
            $this->settings->set('ai.provider', $data['provider'], $familyId, $actorId);
            $changes['provider'] = $data['provider'];
        }

        if (! empty($data['clear_gemini_api_key'])) {
            $this->settings->set('ai.gemini_api_key', '', $familyId, $actorId);
            $changes['gemini_api_key'] = 'cleared';
        } elseif (array_key_exists('gemini_api_key', $data) && is_string($data['gemini_api_key']) && trim($data['gemini_api_key']) !== '') {
            $this->settings->set('ai.gemini_api_key', trim($data['gemini_api_key']), $familyId, $actorId);
            $changes['gemini_api_key'] = 'updated';
        }

        if (array_key_exists('gemini_model', $data) && is_string($data['gemini_model'])) {
            $model = trim($data['gemini_model']);
            if ($model !== '') {
                $this->settings->set('ai.gemini_model', $model, $familyId, $actorId);
                $changes['gemini_model'] = $model;
            }
        }

        if (! empty($data['clear_qwen_api_key'])) {
            $this->settings->set('ai.qwen_api_key', '', $familyId, $actorId);
            $changes['qwen_api_key'] = 'cleared';
        } elseif (array_key_exists('qwen_api_key', $data) && is_string($data['qwen_api_key']) && trim($data['qwen_api_key']) !== '') {
            $this->settings->set('ai.qwen_api_key', trim($data['qwen_api_key']), $familyId, $actorId);
            $changes['qwen_api_key'] = 'updated';
        }

        if (array_key_exists('qwen_model', $data) && is_string($data['qwen_model'])) {
            $model = trim($data['qwen_model']);
            if ($model !== '') {
                $this->settings->set('ai.qwen_model', $model, $familyId, $actorId);
                $changes['qwen_model'] = $model;
            }
        }

        if (! empty($data['clear_openai_api_key'])) {
            $this->settings->set('ai.openai_api_key', '', $familyId, $actorId);
            $changes['openai_api_key'] = 'cleared';
        } elseif (array_key_exists('openai_api_key', $data) && is_string($data['openai_api_key']) && trim($data['openai_api_key']) !== '') {
            $this->settings->set('ai.openai_api_key', trim($data['openai_api_key']), $familyId, $actorId);
            $changes['openai_api_key'] = 'updated';
        }

        if (array_key_exists('openai_model', $data) && is_string($data['openai_model'])) {
            $model = trim($data['openai_model']);
            if ($model !== '') {
                $this->settings->set('ai.openai_model', $model, $familyId, $actorId);
                $changes['openai_model'] = $model;
            }
        }

        if (! empty($data['clear_openai_base_url'])) {
            $this->settings->set('ai.openai_base_url', '', $familyId, $actorId);
            $changes['openai_base_url'] = 'cleared';
        } elseif (array_key_exists('openai_base_url', $data) && is_string($data['openai_base_url'])) {
            $base = trim($data['openai_base_url']);
            if ($base !== '') {
                if (! filter_var($base, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($base), 'http')) {
                    throw ValidationException::withMessages([
                        'openai_base_url' => '请填写以 http:// 或 https:// 开头的中转站地址。',
                    ]);
                }
                $normalized = FamilyAiConfig::normalizeOpenAiBaseUrl($base);
                $this->settings->set('ai.openai_base_url', $normalized, $familyId, $actorId);
                $changes['openai_base_url'] = $normalized;
            }
        }

        if (isset($data['tts_provider'])) {
            $this->settings->set('tts.provider', $data['tts_provider'], $familyId, $actorId);
            $changes['tts_provider'] = $data['tts_provider'];
        }

        if (! empty($data['clear_tts_qwen_api_key'])) {
            $this->settings->set('tts.qwen_api_key', '', $familyId, $actorId);
            $changes['tts_qwen_api_key'] = 'cleared';
        } elseif (array_key_exists('tts_qwen_api_key', $data) && is_string($data['tts_qwen_api_key']) && trim($data['tts_qwen_api_key']) !== '') {
            $this->settings->set('tts.qwen_api_key', trim($data['tts_qwen_api_key']), $familyId, $actorId);
            $changes['tts_qwen_api_key'] = 'updated';
        }

        if (array_key_exists('tts_voice', $data) && is_string($data['tts_voice'])) {
            $voice = trim($data['tts_voice']);
            if ($voice !== '') {
                $this->settings->set('tts.voice', $voice, $familyId, $actorId);
                $changes['tts_voice'] = $voice;
            }
        }

        if (array_key_exists('tts_voice_en', $data) && is_string($data['tts_voice_en'])) {
            $voiceEn = trim($data['tts_voice_en']);
            if ($voiceEn !== '') {
                $this->settings->set('tts.voice_en', $voiceEn, $familyId, $actorId);
                $changes['tts_voice_en'] = $voiceEn;
            }
        }

        if ($changes !== []) {
            $this->audit->log('ai.settings_updated', $familyId, null, $changes);
        }

        return response()->json(['data' => $this->aiConfig->present($familyId)]);
    }
}
