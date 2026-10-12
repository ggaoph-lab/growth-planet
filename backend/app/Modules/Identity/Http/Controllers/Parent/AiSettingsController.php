<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\FamilyAiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiSettingsController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly FamilyAiConfig $aiConfig,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->aiConfig->present($this->context->familyId())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['sometimes', 'string', Rule::in(['auto', 'gemini', 'qwen', 'rules'])],
            'gemini_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'gemini_model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'qwen_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'qwen_model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clear_gemini_api_key' => ['sometimes', 'boolean'],
            'clear_qwen_api_key' => ['sometimes', 'boolean'],
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

        if ($changes !== []) {
            $this->audit->log('ai.settings_updated', $familyId, null, $changes);
        }

        return response()->json(['data' => $this->aiConfig->present($familyId)]);
    }
}
