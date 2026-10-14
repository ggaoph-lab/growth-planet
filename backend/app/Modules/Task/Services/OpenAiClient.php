<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;
use App\Modules\Task\Support\LlmFailureTracker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChatGPT / OpenAI chat-completions client.
 * Keys stay server-side; never expose to the frontend.
 */
class OpenAiClient implements HomeworkLlmClient
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(?array $config = null): bool
    {
        return $this->apiKey($config) !== '';
    }

    public function chatJson(array $messages, ?array $config = null): ?array
    {
        $apiKey = $this->apiKey($config);
        if ($apiKey === '') {
            LlmFailureTracker::set('no_key');

            return null;
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.openai.base_url')), '/');
        $model = (string) ($config['model'] ?? config('services.openai.model', 'gpt-4o-mini'));
        $timeout = max(5, (int) ($config['timeout'] ?? config('services.openai.timeout', 30)));

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (ConnectionException $e) {
            HomeworkLlmTimeoutException::throwIfTimeout($this->name(), $e);
            LlmFailureTracker::set('unreachable');
            Log::warning('openai.connection_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            LlmFailureTracker::set(LlmFailureTracker::fromHttpStatus($response->status()));
            Log::warning('openai.chat_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            LlmFailureTracker::set('empty');
            Log::warning('openai.empty_content', ['json' => $response->json()]);

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

        return trim((string) config('services.openai.api_key', ''));
    }
}
