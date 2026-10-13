<?php

namespace App\Modules\Task\Services;

use App\Modules\Core\Settings\Settings;

/**
 * Resolves per-family AI credentials with env fallbacks.
 * Family UI keys win; empty family key falls back to server .env.
 */
class FamilyAiConfig
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{
     *   provider: string,
     *   gemini_api_key: string,
     *   gemini_model: string,
     *   qwen_api_key: string,
     *   qwen_model: string,
     *   openai_api_key: string,
     *   openai_model: string,
     *   openai_base_url: string,
     *   gemini_configured: bool,
     *   qwen_configured: bool,
     *   openai_configured: bool,
     *   gemini_source: 'family'|'env'|'none',
     *   qwen_source: 'family'|'env'|'none',
     *   openai_source: 'family'|'env'|'none'
     * }
     */
    public function resolve(?int $familyId): array
    {
        $provider = $familyId !== null
            ? (string) $this->settings->get('ai.provider', $familyId)
            : (string) config('services.homework_llm.provider', 'auto');

        [$geminiKey, $geminiSource] = $this->resolveSecret(
            $familyId,
            'ai.gemini_api_key',
            (string) config('services.gemini.api_key', ''),
        );
        [$qwenKey, $qwenSource] = $this->resolveSecret(
            $familyId,
            'ai.qwen_api_key',
            (string) config('services.qwen.api_key', ''),
        );
        [$openaiKey, $openaiSource] = $this->resolveSecret(
            $familyId,
            'ai.openai_api_key',
            (string) config('services.openai.api_key', ''),
        );

        $geminiModel = $familyId !== null
            ? (string) $this->settings->get('ai.gemini_model', $familyId)
            : (string) config('services.gemini.model', 'gemini-2.5-flash');
        $qwenModel = $familyId !== null
            ? (string) $this->settings->get('ai.qwen_model', $familyId)
            : (string) config('services.qwen.model', 'qwen-turbo');
        $openaiModel = $familyId !== null
            ? (string) $this->settings->get('ai.openai_model', $familyId)
            : (string) config('services.openai.model', 'gpt-4o-mini');

        if ($geminiModel === '') {
            $geminiModel = (string) config('services.gemini.model', 'gemini-2.5-flash');
        }
        if ($qwenModel === '') {
            $qwenModel = (string) config('services.qwen.model', 'qwen-turbo');
        }
        if ($openaiModel === '') {
            $openaiModel = (string) config('services.openai.model', 'gpt-4o-mini');
        }

        return [
            'provider' => $provider !== '' ? $provider : 'auto',
            'gemini_api_key' => $geminiKey,
            'gemini_model' => $geminiModel,
            'qwen_api_key' => $qwenKey,
            'qwen_model' => $qwenModel,
            'openai_api_key' => $openaiKey,
            'openai_model' => $openaiModel,
            'openai_base_url' => (string) config('services.openai.base_url', 'https://api.openai.com/v1'),
            'gemini_configured' => $geminiKey !== '',
            'qwen_configured' => $qwenKey !== '',
            'openai_configured' => $openaiKey !== '',
            'gemini_source' => $geminiSource,
            'qwen_source' => $qwenSource,
            'openai_source' => $openaiSource,
        ];
    }

    /**
     * Public summary for parent UI — never includes raw secrets.
     *
     * @return array<string, mixed>
     */
    public function present(?int $familyId): array
    {
        $cfg = $this->resolve($familyId);

        return [
            'provider' => $cfg['provider'],
            'gemini' => [
                'configured' => $cfg['gemini_configured'],
                'source' => $cfg['gemini_source'],
                'model' => $cfg['gemini_model'],
                'model_customized' => $familyId !== null && $this->settings->has('ai.gemini_model', $familyId),
                'hint' => $this->maskHint($cfg['gemini_api_key']),
            ],
            'qwen' => [
                'configured' => $cfg['qwen_configured'],
                'source' => $cfg['qwen_source'],
                'model' => $cfg['qwen_model'],
                'model_customized' => $familyId !== null && $this->settings->has('ai.qwen_model', $familyId),
                'hint' => $this->maskHint($cfg['qwen_api_key']),
            ],
            'openai' => [
                'configured' => $cfg['openai_configured'],
                'source' => $cfg['openai_source'],
                'model' => $cfg['openai_model'],
                'model_customized' => $familyId !== null && $this->settings->has('ai.openai_model', $familyId),
                'hint' => $this->maskHint($cfg['openai_api_key']),
            ],
        ];
    }

    /**
     * @return array{0: string, 1: 'family'|'env'|'none'}
     */
    private function resolveSecret(?int $familyId, string $settingKey, string $envFallback): array
    {
        if ($familyId !== null) {
            $familyKey = trim((string) $this->settings->get($settingKey, $familyId));
            if ($familyKey !== '') {
                return [$familyKey, 'family'];
            }
        }

        $env = trim($envFallback);
        if ($env !== '') {
            return [$env, 'env'];
        }

        return ['', 'none'];
    }

    private function maskHint(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $len = mb_strlen($key);
        if ($len <= 8) {
            return str_repeat('•', max(4, $len));
        }

        return mb_substr($key, 0, 3).str_repeat('•', 6).mb_substr($key, -4);
    }
}
