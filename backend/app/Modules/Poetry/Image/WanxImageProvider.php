<?php

namespace App\Modules\Poetry\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DashScope 通义万相 (wanx) text-to-image.
 * @see https://help.aliyun.com/zh/model-studio/developer-reference/text-to-image-api
 */
class WanxImageProvider implements ImageProvider
{
    public function name(): string
    {
        return 'wanx';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('poetry.image.wanx.api_key', '')) !== '';
    }

    public function generate(string $prompt): array
    {
        $apiKey = trim((string) config('poetry.image.wanx.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Wanx API key missing');
        }

        $base = rtrim((string) config('poetry.image.wanx.base_url'), '/');
        $model = (string) config('poetry.image.wanx.model', 'wanx-v1');
        $timeout = max(15, (int) config('poetry.image.wanx.timeout', 60));

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->withHeaders(['X-DashScope-Async' => 'enable'])
            ->post($base.'/services/aigc/text2image/image-synthesis', [
                'model' => $model,
                'input' => ['prompt' => $prompt],
                'parameters' => [
                    'size' => '1024*1024',
                    'n' => 1,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('poetry.wanx_create_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);
            throw new RuntimeException('Wanx create task failed');
        }

        $taskId = data_get($response->json(), 'output.task_id');
        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('Wanx task_id missing');
        }

        $url = $this->pollTask($base, $apiKey, $taskId, $timeout);
        if ($url === null) {
            throw new RuntimeException('Wanx image URL missing');
        }

        return ['url' => $url, 'model' => $model];
    }

    private function pollTask(string $base, string $apiKey, string $taskId, int $timeout): ?string
    {
        $deadline = microtime(true) + min($timeout, 55);
        while (microtime(true) < $deadline) {
            usleep(1_500_000);
            $response = Http::timeout(15)
                ->withToken($apiKey)
                ->acceptJson()
                ->get($base.'/tasks/'.$taskId);

            if (! $response->successful()) {
                continue;
            }
            $status = (string) data_get($response->json(), 'output.task_status', '');
            if ($status === 'SUCCEEDED') {
                $url = data_get($response->json(), 'output.results.0.url');

                return is_string($url) ? $url : null;
            }
            if (in_array($status, ['FAILED', 'UNKNOWN', 'CANCELED'], true)) {
                Log::warning('poetry.wanx_task_failed', ['status' => $status, 'body' => mb_substr($response->body(), 0, 400)]);

                return null;
            }
        }

        return null;
    }
}
