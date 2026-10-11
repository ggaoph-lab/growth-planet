<?php

namespace App\Modules\Movie\Services;

use App\Modules\Movie\Models\Movie;
use Illuminate\Support\Collection;

/**
 * Parent suggestion wall. Merges the curated shelf with TMDB discover results,
 * keeps one card per movie, and omits titles already in the family library.
 */
class SuggestionWall
{
    public function __construct(
        private readonly TmdbClient $tmdb,
    ) {}

    /**
     * @param  Collection<int, Movie>  $library
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $library): array
    {
        $ownedIds = [];
        $ownedTitles = [];
        foreach ($library as $movie) {
            $this->remember($ownedIds, $ownedTitles, $movie->external_id, $movie->title_zh, $movie->original_title);
        }

        $seenIds = [];
        $seenTitles = [];
        $cards = [];
        foreach ($this->sources() as $card) {
            $card = $this->present($card);
            $externalId = $this->text($card['external_id'] ?? null);
            $key = $this->text($card['key'] ?? null);
            if ($this->known($ownedIds, $ownedTitles, $externalId, $key, $card['title_zh'] ?? null, $card['original_title'] ?? null)) {
                continue;
            }
            if ($this->known($seenIds, $seenTitles, $externalId, $key, $card['title_zh'] ?? null, $card['original_title'] ?? null)) {
                continue;
            }
            $this->remember($seenIds, $seenTitles, $externalId, $card['title_zh'] ?? null, $card['original_title'] ?? null);
            if ($key !== '') {
                $seenIds[$key] = true;
            }
            $cards[] = $card;
        }

        return $this->shuffle($cards);
    }

    /**
     * Fresh order on every visit so the wall does not feel static.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function shuffle(array $cards): array
    {
        $count = count($cards);
        if ($count < 2) {
            return $cards;
        }

        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
        }

        return array_values($cards);
    }

    /**
     * Curated cards first so a title that also exists on TMDB keeps its poster and series import.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(): array
    {
        $cards = BuiltinMovieSuggestions::cards();
        if ($this->tmdb->isConfigured()) {
            array_push($cards, ...$this->tmdb->discoverFamily());
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function present(array $card): array
    {
        $collection = $card['belongs_to_collection'] ?? null;
        $card['series_key'] = $card['series_key'] ?? (is_array($collection) ? ($collection['external_id'] ?? null) : null);
        $card['series_name'] = $card['series_name'] ?? (is_array($collection) ? ($collection['name'] ?? null) : null);
        $card['poster_url'] = $card['poster_url'] ?? '';

        return $card;
    }

    /**
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $titles
     */
    private function known(array $ids, array $titles, string $externalId, string $key, mixed $title, mixed $original): bool
    {
        if ($externalId !== '' && isset($ids[$externalId])) {
            return true;
        }
        if ($key !== '' && isset($ids[$key])) {
            return true;
        }
        $titleKey = $this->norm($title);
        if ($titleKey !== '' && isset($titles[$titleKey])) {
            return true;
        }
        $originalKey = $this->norm($original);

        return $originalKey !== '' && isset($titles[$originalKey]);
    }

    /**
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $titles
     */
    private function remember(array &$ids, array &$titles, mixed $externalId, mixed $title, mixed $original): void
    {
        $id = $this->text($externalId);
        if ($id !== '') {
            $ids[$id] = true;
        }
        foreach ([$title, $original] as $value) {
            $key = $this->norm($value);
            if ($key !== '') {
                $titles[$key] = true;
            }
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function norm(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === '') {
            return '';
        }
        $collapsed = preg_replace('/\s+/u', '', $text) ?? $text;

        return mb_strtolower($collapsed);
    }
}
