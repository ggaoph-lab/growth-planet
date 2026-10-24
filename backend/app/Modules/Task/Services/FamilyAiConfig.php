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
     *   openai_source: 'family'|'env'|'none',
     *   tts_provider: string,
     *   tts_qwen_api_key: string,
     *   tts_qwen_configured: bool,
     *   tts_qwen_source: 'tts'|'ai'|'env'|'none',
     *   tts_voice: string,
     *   tts_voice_en: string
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
            : (string) config('services.gemini.model', 'gemini-3.1-flash-lite');
        $qwenModel = $familyId !== null
            ? (string) $this->settings->get('ai.qwen_model', $familyId)
            : (string) config('services.qwen.model', 'qwen-turbo');
        $openaiModel = $familyId !== null
            ? (string) $this->settings->get('ai.openai_model', $familyId)
            : (string) config('services.openai.model', 'gpt-4o-mini');

        if ($geminiModel === '') {
            $geminiModel = (string) config('services.gemini.model', 'gemini-3.1-flash-lite');
        }
        if ($qwenModel === '') {
            $qwenModel = (string) config('services.qwen.model', 'qwen-turbo');
        }
        if ($openaiModel === '') {
            $openaiModel = (string) config('services.openai.model', 'gpt-4o-mini');
        }

        $openaiBaseUrl = '';
        if ($familyId !== null) {
            $openaiBaseUrl = trim((string) $this->settings->get('ai.openai_base_url', $familyId));
        }
        if ($openaiBaseUrl === '') {
            $openaiBaseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        }
        $openaiBaseUrl = self::normalizeOpenAiBaseUrl($openaiBaseUrl);

        [$ttsKey, $ttsSource] = $this->resolveTtsSecret($familyId, $qwenKey, $qwenSource);
        $ttsProvider = $familyId !== null
            ? (string) $this->settings->get('tts.provider', $familyId)
            : 'auto';
        if (! in_array($ttsProvider, ['auto', 'qwen', 'edge'], true)) {
            $ttsProvider = 'auto';
        }
        $ttsVoice = $familyId !== null
            ? trim((string) $this->settings->get('tts.voice', $familyId))
            : '';
        if ($ttsVoice === '') {
            $ttsVoice = (string) config('services.qwen.tts_voice', 'Cherry');
        }
        $ttsVoiceEn = $familyId !== null
            ? trim((string) $this->settings->get('tts.voice_en', $familyId))
            : '';
        if ($ttsVoiceEn === '') {
            $ttsVoiceEn = (string) config('services.qwen.tts_voice_en', 'Jennifer');
        }

        return [
            'provider' => $provider !== '' ? $provider : 'auto',
            'gemini_api_key' => $geminiKey,
            'gemini_model' => $geminiModel,
            'qwen_api_key' => $qwenKey,
            'qwen_model' => $qwenModel,
            'openai_api_key' => $openaiKey,
            'openai_model' => $openaiModel,
            'openai_base_url' => $openaiBaseUrl,
            'gemini_configured' => $geminiKey !== '',
            'qwen_configured' => $qwenKey !== '',
            'openai_configured' => $openaiKey !== '',
            'gemini_source' => $geminiSource,
            'qwen_source' => $qwenSource,
            'openai_source' => $openaiSource,
            'tts_provider' => $ttsProvider,
            'tts_qwen_api_key' => $ttsKey,
            'tts_qwen_configured' => $ttsKey !== '',
            'tts_qwen_source' => $ttsSource,
            'tts_voice' => $ttsVoice,
            'tts_voice_en' => $ttsVoiceEn,
        ];
    }

    /** Accept host or …/v1; OpenAiClient appends /chat/completions. */
    public static function normalizeOpenAiBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        }
        if (preg_match('#/v\d+$#', $url) === 1) {
            return $url;
        }

        return $url.'/v1';
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
                'base_url' => $cfg['openai_base_url'],
                'base_url_customized' => $familyId !== null && $this->settings->has('ai.openai_base_url', $familyId)
                    && trim((string) $this->settings->get('ai.openai_base_url', $familyId)) !== '',
            ],
            'tts' => [
                'provider' => $cfg['tts_provider'],
                'configured' => $cfg['tts_qwen_configured'],
                'source' => $cfg['tts_qwen_source'],
                'hint' => $this->maskHint($cfg['tts_qwen_api_key']),
                'voice' => $cfg['tts_voice'],
                'voice_en' => $cfg['tts_voice_en'],
                'voice_customized' => $familyId !== null && $this->settings->has('tts.voice', $familyId)
                    && trim((string) $this->settings->get('tts.voice', $familyId)) !== '',
                'voice_en_customized' => $familyId !== null && $this->settings->has('tts.voice_en', $familyId)
                    && trim((string) $this->settings->get('tts.voice_en', $familyId)) !== '',
                'voices' => app(QwenTtsVoiceCatalog::class)->all(),
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

    /**
     * TTS key: dedicated tts.qwen_api_key → content ai.qwen_api_key → env.
     *
     * @param  'family'|'env'|'none'  $aiQwenSource
     * @return array{0: string, 1: 'tts'|'ai'|'env'|'none'}
     */
    private function resolveTtsSecret(?int $familyId, string $aiQwenKey, string $aiQwenSource): array
    {
        if ($familyId !== null) {
            $ttsKey = trim((string) $this->settings->get('tts.qwen_api_key', $familyId));
            if ($ttsKey !== '') {
                return [$ttsKey, 'tts'];
            }
        }

        if ($aiQwenKey !== '') {
            return [$aiQwenKey, $aiQwenSource === 'family' ? 'ai' : 'env'];
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
