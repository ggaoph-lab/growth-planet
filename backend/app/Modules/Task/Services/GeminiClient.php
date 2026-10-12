<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini generateContent client for homework structuring.
 * Suited to Singapore/global hosts; keys stay server-side only.
 */
class GeminiClient implements HomeworkLlmClient
{
    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(?array $config = null): bool
    {
        return $this->apiKey($config) !== '';
    }

    public function chatJson(array $messages, ?array $config = null): ?array
    {
        $apiKey = $this->apiKey($config);
        if ($apiKey === '') {
            return null;
        }

        $system = '';
        $userParts = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $system = $system === '' ? $content : $system."\n\n".$content;
            } else {
                $userParts[] = $content;
            }
        }

        $userText = trim(implode("\n\n", $userParts));
        if ($userText === '') {
            return null;
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.gemini.base_url')), '/');
        $model = (string) ($config['model'] ?? config('services.gemini.model', 'gemini-2.5-flash'));
        $timeout = max(5, (int) ($config['timeout'] ?? config('services.gemini.timeout', 30)));
        $url = $baseUrl.'/models/'.$model.':generateContent';

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
            ],
        ];
        if ($system !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $response = Http::timeout($timeout)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('gemini.chat_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $parts = data_get($response->json(), 'candidates.0.content.parts');
        $content = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $content .= $part['text'];
                }
            }
        }

        if (trim($content) === '') {
            Log::warning('gemini.empty_content', ['json' => $response->json()]);

            return null;
        }

        return [
            'content' => $content,
            'model' => (string) data_get($response->json(), 'modelVersion', $model),
        ];
    }

    /**
     * @param  array{api_key?: string}|null  $config
     */
    private function apiKey(?array $config): string
    {
        $fromConfig = trim((string) ($config['api_key'] ?? ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return trim((string) config('services.gemini.api_key', ''));
    }
}
