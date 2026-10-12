<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin DashScope OpenAI-compatible client for homework structuring.
 * Keys stay server-side; never expose to the frontend.
 */
class QwenClient implements HomeworkLlmClient
{
    public function name(): string
    {
        return 'qwen';
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

        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.qwen.base_url')), '/');
        $model = (string) ($config['model'] ?? config('services.qwen.model', 'qwen-turbo'));
        $timeout = max(5, (int) ($config['timeout'] ?? config('services.qwen.timeout', 30)));

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            Log::warning('qwen.chat_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            Log::warning('qwen.empty_content', ['json' => $response->json()]);

            return null;
        }

        return [
            'content' => $content,
            'model' => (string) data_get($response->json(), 'model', $model),
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

        return trim((string) config('services.qwen.api_key', ''));
    }
}
