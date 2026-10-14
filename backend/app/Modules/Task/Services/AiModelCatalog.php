<?php

namespace App\Modules\Task\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lists chat models a provider API key can call, newest first.
 * After listing, each candidate is probed with a tiny chat call so retired
 * or blocked model ids never appear in the parent UI.
 * Keys stay server-side; the response never includes them.
 */
class AiModelCatalog
{
    private const PROBE_LIMIT = 18;

    private const PROBE_TIMEOUT = 5;

    private const PROBE_CHUNK = 8;

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string, probed: bool}
     */
    public function list(string $provider, string $apiKey, string $baseUrl, ?string $preferredModel = null): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return $this->emptyResult($provider, 'not_configured');
        }

        $baseUrl = rtrim($baseUrl, '/');
        $preferredModel = is_string($preferredModel) ? trim($preferredModel) : '';
        $cacheKey = 'ai-model-catalog:v2:'.hash('sha256', $provider.'|'.$baseUrl.'|'.$apiKey.'|'.$preferredModel);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['models'], $cached['latest'], $cached['probed'])) {
            return $cached;
        }

        $result = match ($provider) {
            'gemini' => $this->fetchGemini($apiKey, $baseUrl),
            'openai' => $this->fetchOpenAiList($apiKey, $baseUrl.'/models', 'openai'),
            'qwen' => $this->fetchQwen($apiKey, $baseUrl),
            default => $this->emptyResult($provider, 'upstream_failed'),
        };

        if ($result['error'] === null && $result['models'] !== []) {
            $result = $this->filterAvailable($provider, $apiKey, $baseUrl, $result, $preferredModel !== '' ? $preferredModel : null);
        }

        if ($result['error'] === null && $result['models'] !== []) {
            Cache::put($cacheKey, $result, 600);
        }

        return $result;
    }

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string}
     */
    private function fetchGemini(string $apiKey, string $baseUrl): array
    {
        $rows = [];
        $pageToken = null;

        for ($page = 0; $page < 4; $page++) {
            $query = ['pageSize' => 1000];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = Http::timeout(12)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->acceptJson()
                    ->get($baseUrl.'/models', $query);
            } catch (ConnectionException $e) {
                Log::warning('ai.models_failed', ['provider' => 'gemini', 'error' => $e->getMessage()]);

                return $this->emptyResult('gemini', 'upstream_failed');
            }

            if (! $response->successful()) {
                Log::warning('ai.models_failed', ['provider' => 'gemini', 'status' => $response->status()]);

                return $this->emptyResult('gemini', $this->errorFromStatus($response->status()));
            }

            $json = $response->json();
            $batch = is_array($json) ? ($json['models'] ?? []) : [];
            if (! is_array($batch)) {
                return $this->emptyResult('gemini', 'upstream_failed');
            }

            foreach ($batch as $model) {
                if (! is_array($model) || ! $this->geminiSupportsChat($model)) {
                    continue;
                }
                $id = $this->geminiId((string) ($model['name'] ?? ''));
                if ($id === '' || ! $this->isGeminiChat($id)) {
                    continue;
                }
                $label = trim((string) ($model['displayName'] ?? ''));
                $rows[$id] = $this->row($id, $label !== '' ? $label : $id, 0);
            }

            $next = is_array($json) ? ($json['nextPageToken'] ?? null) : null;
            if (! is_string($next) || $next === '' || $next === $pageToken) {
                break;
            }
            $pageToken = $next;
        }

        return $this->finish('gemini', $rows, null);
    }

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string}
     */
    private function fetchQwen(string $apiKey, string $baseUrl): array
    {
        if (str_contains($baseUrl, 'dashscope-intl.aliyuncs.com')) {
            $native = $this->fetchQwenNative($apiKey, 'https://dashscope-intl.aliyuncs.com/api/v1/models');
            if ($native['error'] !== 'upstream_failed') {
                return $native;
            }
        }

        return $this->fetchOpenAiList($apiKey, $baseUrl.'/models', 'qwen');
    }

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string}
     */
    private function fetchQwenNative(string $apiKey, string $url): array
    {
        $rows = [];

        for ($page = 1; $page <= 5; $page++) {
            try {
                $response = Http::timeout(12)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->get($url, [
                        'capabilities' => 'TG',
                        'providers' => 'qwen',
                        'page_no' => $page,
                        'page_size' => 100,
                    ]);
            } catch (ConnectionException $e) {
                Log::warning('ai.models_failed', ['provider' => 'qwen', 'error' => $e->getMessage()]);

                return $this->emptyResult('qwen', 'upstream_failed');
            }

            if (! $response->successful()) {
                Log::warning('ai.models_failed', ['provider' => 'qwen', 'status' => $response->status()]);

                return $this->emptyResult('qwen', $this->errorFromStatus($response->status()));
            }

            $json = $response->json();
            $batch = is_array($json) ? ($json['output']['models'] ?? null) : null;
            if (! is_array($batch)) {
                return $this->emptyResult('qwen', 'upstream_failed');
            }

            foreach ($batch as $model) {
                if (! is_array($model)) {
                    continue;
                }
                $id = trim((string) ($model['model'] ?? ''));
                if ($id === '' || ! $this->isQwenChat($id)) {
                    continue;
                }
                $label = trim((string) ($model['name'] ?? ''));
                $rows[$id] = $this->row($id, $label !== '' ? $label : $id, $this->publishedTime($model['published_time'] ?? null));
            }

            $total = (int) (is_array($json) ? ($json['output']['total'] ?? 0) : 0);
            if ($batch === [] || ($page * 100) >= $total) {
                break;
            }
        }

        return $this->finish('qwen', $rows, null);
    }

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string}
     */
    private function fetchOpenAiList(string $apiKey, string $url, string $provider): array
    {
        try {
            $response = Http::timeout(12)
                ->withToken($apiKey)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('ai.models_failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->emptyResult($provider, 'upstream_failed');
        }

        if (! $response->successful()) {
            Log::warning('ai.models_failed', ['provider' => $provider, 'status' => $response->status()]);

            return $this->emptyResult($provider, $this->errorFromStatus($response->status()));
        }

        $json = $response->json();
        $batch = is_array($json) ? ($json['data'] ?? null) : null;
        if (! is_array($batch)) {
            return $this->emptyResult($provider, 'upstream_failed');
        }

        $rows = [];
        foreach ($batch as $model) {
            if (! is_array($model)) {
                continue;
            }
            $id = trim((string) ($model['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $keep = $provider === 'qwen' ? $this->isQwenChat($id) : $this->isOpenAiChat($id);
            if (! $keep) {
                continue;
            }
            $rows[$id] = $this->row($id, $id, (int) ($model['created'] ?? 0));
        }

        return $this->finish($provider, $rows, null);
    }

    /**
     * @param  array<string, array{id: string, label: string, created: int}>  $rows
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string, probed: bool}
     */
    private function finish(string $provider, array $rows, ?string $error): array
    {
        $models = array_values($rows);
        $hasCreated = false;
        foreach ($models as $model) {
            if ($model['created'] > 0) {
                $hasCreated = true;
                break;
            }
        }

        usort($models, function (array $a, array $b) use ($hasCreated): int {
            if ($hasCreated && $a['created'] !== $b['created']) {
                return $b['created'] <=> $a['created'];
            }

            return $this->compareFreshness($a['id'], $b['id']);
        });

        if (count($models) > 80) {
            $models = array_slice($models, 0, 80);
        }

        $public = array_map(fn (array $model): array => [
            'id' => $model['id'],
            'label' => $model['label'],
        ], $models);

        if ($public === [] && $error === null) {
            $error = 'no_models';
        }

        return [
            'provider' => $provider,
            'models' => $public,
            'latest' => $public[0]['id'] ?? null,
            'error' => $error,
            'probed' => false,
        ];
    }

    /**
     * Drop model ids that reject a tiny chat call (retired, blocked, wrong region).
     * Temporary 429/5xx still count as available so peak load does not empty the list.
     *
     * @param  array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string, probed: bool}  $result
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string, probed: bool}
     */
    private function filterAvailable(string $provider, string $apiKey, string $baseUrl, array $result, ?string $preferredModel): array
    {
        $candidates = $this->probeCandidates($result['models'], $preferredModel);
        if ($candidates === []) {
            return $result;
        }

        $labels = [];
        foreach ($result['models'] as $model) {
            $labels[$model['id']] = $model['label'];
        }

        $availableIds = $this->probeModels($provider, $apiKey, $baseUrl, $candidates);
        $available = [];
        foreach ($result['models'] as $model) {
            if (! isset($availableIds[$model['id']])) {
                continue;
            }
            $available[] = $model;
        }

        // Preferred model may sit outside the probe window; keep it only when it passed.
        if ($preferredModel !== null && $preferredModel !== '' && isset($availableIds[$preferredModel]) && ! $this->hasModel($available, $preferredModel)) {
            $available[] = [
                'id' => $preferredModel,
                'label' => $labels[$preferredModel] ?? $preferredModel,
            ];
        }

        if ($available === []) {
            return [
                'provider' => $provider,
                'models' => [],
                'latest' => null,
                'error' => 'no_models',
                'probed' => true,
            ];
        }

        return [
            'provider' => $provider,
            'models' => $available,
            'latest' => $available[0]['id'],
            'error' => null,
            'probed' => true,
        ];
    }

    /**
     * @param  list<array{id: string, label: string}>  $models
     * @return list<string>
     */
    private function probeCandidates(array $models, ?string $preferredModel): array
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = $model['id'];
            if (count($ids) >= self::PROBE_LIMIT) {
                break;
            }
        }

        if ($preferredModel !== null && $preferredModel !== '' && ! in_array($preferredModel, $ids, true)) {
            foreach ($models as $model) {
                if ($model['id'] === $preferredModel) {
                    $ids[] = $preferredModel;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $modelIds
     * @return array<string, true>
     */
    private function probeModels(string $provider, string $apiKey, string $baseUrl, array $modelIds): array
    {
        $available = [];

        foreach (array_chunk($modelIds, self::PROBE_CHUNK) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($provider, $apiKey, $baseUrl, $chunk) {
                foreach ($chunk as $modelId) {
                    $this->queueProbe($pool, $provider, $apiKey, $baseUrl, $modelId);
                }
            });

            foreach ($chunk as $index => $modelId) {
                $response = $responses[$index] ?? $responses[$modelId] ?? null;
                if ($this->probePassed($response)) {
                    $available[$modelId] = true;
                }
            }
        }

        return $available;
    }

    private function queueProbe(Pool $pool, string $provider, string $apiKey, string $baseUrl, string $modelId): void
    {
        $pending = $pool->as($modelId)->timeout(self::PROBE_TIMEOUT)->acceptJson();

        if ($provider === 'gemini') {
            $pending
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($baseUrl.'/models/'.$modelId.':generateContent', [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => 'ping']],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                        'maxOutputTokens' => 1,
                    ],
                ]);

            return;
        }

        $pending
            ->withToken($apiKey)
            ->post($baseUrl.'/chat/completions', [
                'model' => $modelId,
                'messages' => [
                    ['role' => 'user', 'content' => 'ping'],
                ],
                'temperature' => 0,
                'max_tokens' => 1,
            ]);
    }

    private function probePassed(mixed $response): bool
    {
        if (! $response instanceof Response) {
            // Timeouts / network blips: keep the catalog entry. Hard 4xx below still hide retired ids.
            return true;
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return true;
        }
        // Model exists but is rate-limited or overloaded — still selectable.
        if ($status === 429 || $status >= 500) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{id: string, label: string}>  $models
     */
    private function hasModel(array $models, string $id): bool
    {
        foreach ($models as $model) {
            if ($model['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, label: string, created: int}
     */
    private function row(string $id, string $label, int $created): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'created' => $created,
        ];
    }

    /**
     * @return array{provider: string, models: list<array{id: string, label: string}>, latest: ?string, error: ?string, probed: bool}
     */
    private function emptyResult(string $provider, string $error): array
    {
        return [
            'provider' => $provider,
            'models' => [],
            'latest' => null,
            'error' => $error,
            'probed' => false,
        ];
    }

    private function errorFromStatus(int $status): string
    {
        return in_array($status, [400, 401, 403], true) ? 'invalid_key' : 'upstream_failed';
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function geminiSupportsChat(array $model): bool
    {
        $methods = $model['supportedGenerationMethods'] ?? [];

        return is_array($methods) && in_array('generateContent', $methods, true);
    }

    private function geminiId(string $name): string
    {
        $name = trim($name);
        if (str_starts_with($name, 'models/')) {
            return substr($name, strlen('models/'));
        }

        return $name;
    }

    private function isGeminiChat(string $id): bool
    {
        if (! preg_match('/^(gemini|gemma)/i', $id)) {
            return false;
        }

        return ! preg_match('/embedding|imagen|veo|aqa|tts|live|audio|image|robotics|transcribe|banana/i', $id);
    }

    private function isQwenChat(string $id): bool
    {
        if (! preg_match('/qwen/i', $id)) {
            return false;
        }

        return ! preg_match('/tts|embed|rerank|vl-|vision|audio|image|wanx|cosyvoice|sambert|paraformer|gummy|sensevoice/i', $id);
    }

    private function isOpenAiChat(string $id): bool
    {
        if (str_starts_with($id, 'ft:')) {
            return false;
        }
        if (! preg_match('/^(gpt-|chatgpt-|o\d)/i', $id)) {
            return false;
        }

        return ! preg_match('/embedding|whisper|tts|dall-e|transcribe|realtime|moderation|sora|audio|image/i', $id);
    }

    private function publishedTime(mixed $value): int
    {
        if (! is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * Newer models sort first when the provider does not send a release time.
     */
    private function compareFreshness(string $a, string $b): int
    {
        $left = $this->versionRank($a);
        $right = $this->versionRank($b);
        foreach ([0, 1, 2, 3] as $index) {
            if ($left[$index] !== $right[$index]) {
                return $right[$index] <=> $left[$index];
            }
        }

        return strlen($a) <=> strlen($b);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function versionRank(string $id): array
    {
        $major = 0;
        $minor = 0;
        if (preg_match('/(\d+)(?:\.(\d+))?/', $id, $match)) {
            $major = (int) $match[1];
            $minor = (int) ($match[2] ?? 0);
        }

        $stable = preg_match('/preview|exp|experimental|beta/i', $id) || preg_match('/20\d{2}/', $id) || preg_match('/-\d{3}$/', $id)
            ? 0
            : 1;

        if (preg_match('/lite|turbo|nano|mini/i', $id)) {
            $tier = 0;
        } elseif (preg_match('/(?:^|[-.])(?:pro|max)(?:$|[-.])/i', $id)) {
            $tier = 4;
        } elseif (preg_match('/plus/i', $id)) {
            $tier = 3;
        } elseif (preg_match('/flash/i', $id)) {
            $tier = 2;
        } else {
            $tier = 1;
        }

        return [$major, $minor, $stable, $tier];
    }
}
