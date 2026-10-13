<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\AiModelCatalog;
use App\Modules\Task\Services\FamilyAiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class AiSettingsController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly FamilyAiConfig $aiConfig,
        private readonly AiModelCatalog $models,
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
        ]);

        $userId = $this->context->user()->id;
        $throttleKey = 'ai-models:'.$userId;
        if (RateLimiter::tooManyAttempts($throttleKey, 30)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $provider = (string) $data['provider'];
        $cfg = $this->aiConfig->resolve($this->context->familyId());
        $draft = trim((string) ($data['api_key'] ?? ''));
        $apiKey = $draft !== '' ? $draft : (string) $cfg[$provider.'_api_key'];
        $baseUrl = match ($provider) {
            'gemini' => (string) config('services.gemini.base_url'),
            'openai' => (string) $cfg['openai_base_url'],
            default => (string) config('services.qwen.base_url'),
        };

        return response()->json([
            'data' => $this->models->list($provider, $apiKey, $baseUrl),
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
            'clear_gemini_api_key' => ['sometimes', 'boolean'],
            'clear_qwen_api_key' => ['sometimes', 'boolean'],
            'clear_openai_api_key' => ['sometimes', 'boolean'],
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

        if ($changes !== []) {
            $this->audit->log('ai.settings_updated', $familyId, null, $changes);
        }

        return response()->json(['data' => $this->aiConfig->present($familyId)]);
    }
}
