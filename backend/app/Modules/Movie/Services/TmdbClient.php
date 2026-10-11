<?php

namespace App\Modules\Movie\Services;

use Illuminate\Support\Facades\Http;

class TmdbClient
{
    public function isConfigured(): bool
    {
        $key = config('services.tmdb.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/search/movie', [
            'api_key' => config('services.tmdb.api_key'),
            'query' => $query,
            'language' => 'zh-CN',
            'include_adult' => false,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results') ?? [];

        return array_map(fn (array $row) => $this->mapSearchRow($row), $results);
    }

    /**
     * Family-leaning animated titles for the parent suggestion wall.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverFamily(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/discover/movie', [
            'api_key' => config('services.tmdb.api_key'),
            'language' => 'zh-CN',
            'include_adult' => false,
            'sort_by' => 'popularity.desc',
            'certification_country' => 'US',
            'certification.lte' => 'PG',
            'with_genres' => '16',
        ]);

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results') ?? [];

        return array_values(array_map(function (array $row) {
            $mapped = $this->mapSearchRow($row);

            return [
                'key' => 'tmdb:'.$mapped['external_id'],
                'source' => 'tmdb',
                'external_id' => $mapped['external_id'],
                'title_zh' => $mapped['title_zh'],
                'original_title' => $mapped['original_title'],
                'release_year' => $mapped['release_year'],
                'runtime_minutes' => null,
                'overview' => $mapped['overview'],
                'belongs_to_collection' => $mapped['belongs_to_collection'],
            ];
        }, array_slice($results, 0, 12)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapSearchRow(array $row): array
    {
        return [
            'external_id' => (string) ($row['id'] ?? ''),
            'title_zh' => (string) ($row['title'] ?? ''),
            'original_title' => (string) ($row['original_title'] ?? ''),
            'release_year' => isset($row['release_date']) ? (int) substr((string) $row['release_date'], 0, 4) : null,
            'overview' => (string) ($row['overview'] ?? ''),
            'poster_path' => $row['poster_path'] ?? null,
            'belongs_to_collection' => isset($row['belongs_to_collection']['id'])
                ? [
                    'external_id' => (string) $row['belongs_to_collection']['id'],
                    'name' => (string) ($row['belongs_to_collection']['name'] ?? ''),
                ]
                : null,
        ];
    }

    /**
     * Fetch a TMDB collection (franchise). Returns null when unavailable.
     *
     * @return array{external_id:string, name:string, overview:?string, parts:list<array{external_id:string, title_zh:string, release_year:?int}>}|null
     */
    public function collection(string $externalId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/collection/'.$externalId, [
            'api_key' => config('services.tmdb.api_key'),
            'language' => 'zh-CN',
        ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $parts = [];
        foreach ($json['parts'] ?? [] as $part) {
            if (! is_array($part) || ! isset($part['id'])) {
                continue;
            }
            $parts[] = [
                'external_id' => (string) $part['id'],
                'title_zh' => (string) ($part['title'] ?? ''),
                'release_year' => isset($part['release_date']) ? (int) substr((string) $part['release_date'], 0, 4) : null,
            ];
        }

        return [
            'external_id' => (string) ($json['id'] ?? $externalId),
            'name' => (string) ($json['name'] ?? ''),
            'overview' => isset($json['overview']) ? (string) $json['overview'] : null,
            'parts' => $parts,
        ];
    }

    /**
     * Movie detail including belongs_to_collection (search results often omit it).
     *
     * @return array{external_id:string, belongs_to_collection:?array{external_id:string, name:string}}|null
     */
    public function movie(string $externalId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/movie/'.$externalId, [
            'api_key' => config('services.tmdb.api_key'),
            'language' => 'zh-CN',
        ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $collection = null;
        if (isset($json['belongs_to_collection']['id'])) {
            $collection = [
                'external_id' => (string) $json['belongs_to_collection']['id'],
                'name' => (string) ($json['belongs_to_collection']['name'] ?? ''),
            ];
        }

        return [
            'external_id' => (string) ($json['id'] ?? $externalId),
            'belongs_to_collection' => $collection,
        ];
    }
}
