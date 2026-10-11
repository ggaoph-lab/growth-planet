<?php

namespace App\Modules\Movie\Services;

use App\Modules\Movie\Models\Movie;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
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

        $zh = $this->searchLanguage($query, 'zh-CN');
        $enById = $this->overviewById($this->searchLanguage($query, 'en-US'));

        return array_map(function (array $row) use ($enById) {
            $row['overview'] = $this->joinOverview($row['overview'] ?? '', $enById[$row['external_id']] ?? '');

            return $row;
        }, $zh);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchLanguage(string $query, string $language): array
    {
        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/search/movie', [
            'api_key' => config('services.tmdb.api_key'),
            'query' => $query,
            'language' => $language,
            'include_adult' => false,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results') ?? [];

        return array_map(fn (array $row) => $this->mapSearchRow($row), array_values(array_filter($results, 'is_array')));
    }

    /**
     * Family animation for the parent suggestion wall.
     * Two shelves from the same catalog: US PG animation, then Chinese-language animation.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverFamily(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $byId = [];
        foreach ($this->discoverQueries() as $query) {
            $enQuery = $query;
            $enQuery['language'] = 'en-US';
            $enById = $this->overviewById(array_map(
                fn (array $row) => $this->mapSearchRow($row),
                array_slice($this->discover($enQuery), 0, 12),
            ));
            foreach (array_slice($this->discover($query), 0, 12) as $row) {
                $mapped = $this->mapSearchRow($row);
                if ($mapped['external_id'] === '') {
                    continue;
                }
                $byId[$mapped['external_id']] = [
                    'key' => 'tmdb:'.$mapped['external_id'],
                    'source' => 'tmdb',
                    'external_id' => $mapped['external_id'],
                    'title_zh' => $mapped['title_zh'],
                    'original_title' => $mapped['original_title'],
                    'release_year' => $mapped['release_year'],
                    'runtime_minutes' => null,
                    'overview' => $this->joinOverview($mapped['overview'], $enById[$mapped['external_id']] ?? ''),
                    'belongs_to_collection' => $mapped['belongs_to_collection'],
                ];
            }
        }

        return array_values($byId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverQueries(): array
    {
        $base = [
            'api_key' => config('services.tmdb.api_key'),
            'language' => 'zh-CN',
            'include_adult' => false,
            'sort_by' => 'popularity.desc',
            'with_genres' => '16',
        ];

        $page = random_int(1, 3);

        return [
            $base + [
                'page' => $page,
                'certification_country' => 'US',
                'certification.lte' => 'PG',
            ],
            $base + [
                'page' => $page,
                'with_original_language' => 'zh',
                'vote_count.gte' => 20,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function discover(array $query): array
    {
        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/discover/movie', $query);
        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, 'is_array'));
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

    /**
     * Chinese paragraph, then a distinct English paragraph when the two differ.
     */
    public function joinOverview(?string $zh, ?string $en): string
    {
        $zh = trim((string) $zh);
        $en = trim((string) $en);
        if ($zh === '') {
            return $en;
        }
        if ($en === '' || $en === $zh) {
            return $zh;
        }

        return $zh."\n\n".$en;
    }

    public function englishOverview(string $externalId): string
    {
        if (! $this->isConfigured() || $externalId === '') {
            return '';
        }

        $response = Http::timeout(8)->get('https://api.themoviedb.org/3/movie/'.$externalId, [
            'api_key' => config('services.tmdb.api_key'),
            'language' => 'en-US',
        ]);
        if (! $response->successful()) {
            return '';
        }

        return trim((string) $response->json('overview'));
    }

    /**
     * @param  Collection<int, Movie>  $movies
     */
    public function fillEnglishOverviews(Collection $movies): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $pending = [];
        foreach ($movies as $movie) {
            if ($movie->source !== 'tmdb' || ! is_string($movie->external_id) || $movie->external_id === '') {
                continue;
            }
            $raw = $movie->getRawOriginal('overview');
            if ($this->isBilingual(is_string($raw) ? $raw : null)) {
                continue;
            }
            $pending[$movie->external_id] = $movie;
        }
        if ($pending === []) {
            return;
        }

        $key = config('services.tmdb.api_key');
        $responses = Http::pool(function ($pool) use ($pending, $key) {
            foreach (array_keys($pending) as $id) {
                $pool->as((string) $id)->timeout(8)->get('https://api.themoviedb.org/3/movie/'.$id, [
                    'api_key' => $key,
                    'language' => 'en-US',
                ]);
            }
        });

        foreach ($pending as $id => $movie) {
            $response = $responses[(string) $id] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $joined = $this->joinOverview(
                is_string($movie->getRawOriginal('overview')) ? $movie->getRawOriginal('overview') : '',
                (string) $response->json('overview'),
            );
            if ($joined === '' || $joined === $movie->getRawOriginal('overview')) {
                continue;
            }
            $movie->overview = $joined;
            $movie->save();
        }
    }

    public function isBilingual(?string $overview): bool
    {
        if (! is_string($overview) || ! str_contains($overview, "\n\n")) {
            return false;
        }
        $parts = preg_split("/\n\s*\n/", trim($overview)) ?: [];
        $second = trim((string) ($parts[1] ?? ''));

        return $second !== '' && preg_match('/[A-Za-z]/', $second) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function overviewById(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (string) ($row['external_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = (string) ($row['overview'] ?? '');
        }

        return $map;
    }
}
