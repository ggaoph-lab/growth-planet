<?php

namespace App\Modules\Poetry\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for 诗泉 (chinese-poetry-api). Public API, no key required.
 * @see https://poetry.palemoky.com/
 */
class ShiQuanClient
{
    /**
     * @return list<array{external_id: string, title: string, author: ?string, dynasty: ?string, content: string}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $base = rtrim((string) config('poetry.shiquan.base_url'), '/');
        $timeout = max(3, (int) config('poetry.shiquan.timeout', 8));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($base.'/api/search', [
                    'q' => $query,
                    'lang' => 'zh-Hans',
                ]);
        } catch (\Throwable $e) {
            Log::warning('poetry.shiquan_search_failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            // Fallback to /api/v1 path used by self-hosted deployments.
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($base.'/api/v1/poems/search', [
                        'q' => $query,
                        'lang' => 'zh-Hans',
                        'page_size' => $limit,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('poetry.shiquan_search_v1_failed', ['error' => $e->getMessage()]);

                return [];
            }
        }

        if (! $response->successful()) {
            Log::warning('poetry.shiquan_http', ['status' => $response->status()]);

            return [];
        }

        $json = $response->json();
        $rows = $json['data'] ?? $json['poems'] ?? $json['results'] ?? $json['items'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapPoem($row);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{external_id: string, title: string, author: ?string, dynasty: ?string, content: string}|null
     */
    private function mapPoem(array $row): ?array
    {
        $title = trim((string) ($row['title'] ?? $row['name'] ?? ''));
        $content = $row['content'] ?? $row['paragraphs'] ?? $row['body'] ?? null;
        if (is_array($content)) {
            $content = implode("\n", array_map('strval', $content));
        }
        $content = trim((string) $content);
        if ($title === '' || $content === '') {
            return null;
        }

        $author = $row['author'] ?? null;
        if (is_array($author)) {
            $author = $author['name'] ?? null;
        }
        $dynasty = $row['dynasty'] ?? null;
        if (is_array($dynasty)) {
            $dynasty = $dynasty['name'] ?? null;
        }

        $id = (string) ($row['id'] ?? $row['uuid'] ?? md5($title.'|'.(string) $author.'|'.$content));

        return [
            'external_id' => $id,
            'title' => $title,
            'author' => $author !== null && $author !== '' ? (string) $author : null,
            'dynasty' => $dynasty !== null && $dynasty !== '' ? (string) $dynasty : null,
            'content' => $content,
        ];
    }
}
