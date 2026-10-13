<?php

namespace App\Modules\English\Services;

use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\HomeworkLlmRouter;
use App\Modules\Task\Services\HomeworkLlmTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Speech-to-text for English Lab speak-along. Uses the family-selected engine only.
 */
class EnglishSpeakStt
{
    public function __construct(
        private readonly FamilyAiConfig $ai,
        private readonly HomeworkLlmRouter $llm,
    ) {}

    /**
     * @return array{transcript: string, provider: string, model: string}|null
     */
    public function transcribe(int $familyId, string $absolutePath, string $mime): ?array
    {
        $cfg = $this->ai->resolve($familyId);
        $choice = strtolower(trim((string) ($cfg['provider'] ?? 'auto')));
        if (! in_array($choice, ['gemini', 'qwen', 'openai'], true)) {
            return null;
        }

        [$primary] = $this->llm->attemptsFor($choice, $familyId);
        if ($primary === null) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        try {
            return match ($primary['client']->name()) {
                'openai' => $this->openai($primary['config'], $absolutePath, $mime, $bytes),
                'qwen' => $this->qwen($primary['config'], $absolutePath, $mime, $bytes),
                'gemini' => $this->gemini($primary['config'], $mime, $bytes),
                default => null,
            };
        } catch (HomeworkLlmTimeoutException) {
            return null;
        }
    }

    /**
     * @param  array{api_key: string, model?: string, base_url?: string, timeout?: int}  $config
     * @return array{transcript: string, provider: string, model: string}|null
     */
    private function openai(array $config, string $path, string $mime, string $bytes): ?array
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            return null;
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.openai.base_url')), '/');
        $timeout = max(10, (int) ($config['timeout'] ?? config('services.openai.timeout', 30)));
        $filename = 'speak.'.$this->extension($mime);

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->attach('file', $bytes, $filename)
                ->post($baseUrl.'/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'json',
                ]);
        } catch (ConnectionException $e) {
            HomeworkLlmTimeoutException::throwIfTimeout('openai', $e);
            Log::warning('english_speak.openai_stt_connection', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('english_speak.openai_stt_failed', ['status' => $response->status()]);

            return null;
        }

        $text = trim((string) data_get($response->json(), 'text', ''));
        if ($text === '') {
            return null;
        }

        return ['transcript' => mb_substr($text, 0, 200), 'provider' => 'openai', 'model' => 'whisper-1'];
    }

    /**
     * @param  array{api_key: string, model?: string, base_url?: string, timeout?: int}  $config
     * @return array{transcript: string, provider: string, model: string}|null
     */
    private function qwen(array $config, string $path, string $mime, string $bytes): ?array
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            return null;
        }
        // DashScope OpenAI-compatible whisper endpoint when available.
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.qwen.base_url')), '/');
        $timeout = max(10, (int) ($config['timeout'] ?? config('services.qwen.timeout', 30)));
        $filename = 'speak.'.$this->extension($mime);

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->attach('file', $bytes, $filename)
                ->post($baseUrl.'/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'json',
                ]);
        } catch (ConnectionException $e) {
            HomeworkLlmTimeoutException::throwIfTimeout('qwen', $e);
            Log::warning('english_speak.qwen_stt_connection', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('english_speak.qwen_stt_failed', ['status' => $response->status()]);

            return null;
        }

        $text = trim((string) data_get($response->json(), 'text', ''));
        if ($text === '') {
            return null;
        }

        return ['transcript' => mb_substr($text, 0, 200), 'provider' => 'qwen', 'model' => 'whisper-1'];
    }

    /**
     * @param  array{api_key: string, model?: string, base_url?: string, timeout?: int}  $config
     * @return array{transcript: string, provider: string, model: string}|null
     */
    private function gemini(array $config, string $mime, string $bytes): ?array
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            return null;
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.gemini.base_url')), '/');
        $model = (string) ($config['model'] ?? config('services.gemini.model', 'gemini-2.5-flash'));
        $timeout = max(10, (int) ($config['timeout'] ?? config('services.gemini.timeout', 30)));
        $url = $baseUrl.'/models/'.$model.':generateContent';

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => 'Transcribe the child speaking English. Reply with JSON only: {"transcript":"..."}. Use only what you hear. No names.']],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inlineData' => [
                        'mimeType' => $this->geminiMime($mime),
                        'data' => base64_encode($bytes),
                    ]],
                    ['text' => 'Transcribe.'],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            HomeworkLlmTimeoutException::throwIfTimeout('gemini', $e);
            Log::warning('english_speak.gemini_stt_connection', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('english_speak.gemini_stt_failed', ['status' => $response->status()]);

            return null;
        }

        $content = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content);
        $decoded = json_decode($content, true);
        $text = trim((string) (is_array($decoded) ? ($decoded['transcript'] ?? '') : ''));
        if ($text === '') {
            return null;
        }

        return [
            'transcript' => mb_substr($text, 0, 200),
            'provider' => 'gemini',
            'model' => $model,
        ];
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/wave' => 'wav',
            default => 'webm',
        };
    }

    private function geminiMime(string $mime): string
    {
        return match ($mime) {
            'audio/webm' => 'audio/webm',
            'audio/mp4', 'audio/m4a' => 'audio/mp4',
            'audio/mpeg' => 'audio/mpeg',
            'audio/wav', 'audio/wave' => 'audio/wav',
            default => 'audio/webm',
        };
    }
}
