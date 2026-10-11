<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Enums\CollectionSortMode;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieCollection;
use App\Modules\Movie\Models\MovieCollectionItem;
use App\Modules\Movie\Models\MovieCollectionPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MovieCollectionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ContentVisibility $visibility,
        private readonly TmdbClient $tmdb,
    ) {}

    /**
     * @param  list<array{movie_id:int, position_story?:int|null, position_release?:int|null, position_custom?:int|null}>  $items
     */
    public function createManual(int $familyId, string $name, ?string $overview, array $items, string $sortMode = 'story'): MovieCollection
    {
        return DB::transaction(function () use ($familyId, $name, $overview, $items, $sortMode) {
            $collection = MovieCollection::query()->create([
                'family_id' => $familyId,
                'source' => 'manual',
                'external_id' => null,
                'name' => $name,
                'overview' => $overview,
                'sort_mode' => CollectionSortMode::from($sortMode)->value,
                'structure_locked' => true,
                'name_locked' => true,
                'overview_locked' => $overview !== null,
            ]);

            $this->replaceItems($collection, $items, 'parent', lockStructure: true);

            return $collection->fresh(['items.movie']);
        });
    }

    /**
     * @param  array{name?:string, overview?:string|null, sort_mode?:string}  $data
     */
    public function update(MovieCollection $collection, array $data): MovieCollection
    {
        if (isset($data['name']) && $data['name'] !== $collection->name) {
            $collection->name = $data['name'];
            $collection->name_locked = true;
        }
        if (array_key_exists('overview', $data) && $data['overview'] !== $collection->overview) {
            $collection->overview = $data['overview'];
            $collection->overview_locked = true;
        }
        if (isset($data['sort_mode'])) {
            $collection->sort_mode = CollectionSortMode::from($data['sort_mode'])->value;
            $collection->structure_locked = true;
        }
        $collection->save();

        return $collection->fresh(['items.movie']);
    }

    /**
     * @param  list<array{movie_id:int, position_story?:int|null, position_release?:int|null, position_custom?:int|null}>  $items
     */
    public function syncItems(MovieCollection $collection, array $items): MovieCollection
    {
        return DB::transaction(function () use ($collection, $items) {
            $this->replaceItems($collection, $items, 'parent', lockStructure: true);

            return $collection->fresh(['items.movie']);
        });
    }

    /**
     * Merge source collections into target. Moves items; soft-deletes sources. Does not touch watch/reward rows.
     *
     * @param  list<int>  $sourceIds
     */
    public function merge(MovieCollection $target, array $sourceIds): MovieCollection
    {
        return DB::transaction(function () use ($target, $sourceIds) {
            $sources = MovieCollection::query()
                ->where('family_id', $target->family_id)
                ->whereIn('id', $sourceIds)
                ->where('id', '!=', $target->id)
                ->get();

            $nextCustom = (int) MovieCollectionItem::query()
                ->where('collection_id', $target->id)
                ->max('position_custom') + 1;

            foreach ($sources as $source) {
                foreach ($source->items()->orderBy('id')->get() as $item) {
                    $exists = MovieCollectionItem::query()->where('movie_id', $item->movie_id)->first();
                    if ($exists !== null && $exists->collection_id === $target->id) {
                        $item->delete();

                        continue;
                    }
                    $item->collection_id = $target->id;
                    $item->membership_source = 'parent';
                    $item->position_custom = $nextCustom++;
                    $item->save();
                }
                $source->structure_locked = true;
                $source->movie_count = 0;
                $source->save();
                $source->delete();
            }

            $target->structure_locked = true;
            $this->refreshCount($target);

            return $target->fresh(['items.movie']);
        });
    }

    /**
     * Split selected movie IDs into a new manual collection.
     *
     * @param  list<int>  $movieIds
     */
    public function split(MovieCollection $source, array $movieIds, string $newName): MovieCollection
    {
        if ($movieIds === []) {
            throw new InvalidArgumentException('split requires movie_ids');
        }

        return DB::transaction(function () use ($source, $movieIds, $newName) {
            $items = MovieCollectionItem::query()
                ->where('collection_id', $source->id)
                ->whereIn('movie_id', $movieIds)
                ->orderBy('id')
                ->get();

            if ($items->count() !== count(array_unique($movieIds))) {
                throw new InvalidArgumentException('some movies are not in the source collection');
            }

            $created = MovieCollection::query()->create([
                'family_id' => $source->family_id,
                'source' => 'manual',
                'name' => $newName,
                'sort_mode' => $source->sort_mode instanceof CollectionSortMode
                    ? $source->sort_mode->value
                    : (string) $source->sort_mode,
                'structure_locked' => true,
                'name_locked' => true,
            ]);

            $pos = 1;
            foreach ($items as $item) {
                $item->collection_id = $created->id;
                $item->membership_source = 'parent';
                $item->position_custom = $pos;
                $item->position_story = $pos;
                $item->save();
                $pos++;
            }

            $source->structure_locked = true;
            $this->refreshCount($source);
            $this->refreshCount($created);

            return $created->fresh(['items.movie']);
        });
    }

    /**
     * Apply TMDB collection metadata without overriding parent locks.
     *
     * @param  array{external_id:string, name:string, overview?:string|null, parts?:list<array{external_id:string, title_zh?:string, release_year?:int|null}>}  $payload
     */
    public function applyTmdbSnapshot(MovieCollection $collection, array $payload, bool $suggestNewPartsOnly = true): MovieCollection
    {
        return DB::transaction(function () use ($collection, $payload, $suggestNewPartsOnly) {
            if (! $collection->name_locked && isset($payload['name'])) {
                $collection->name = $payload['name'];
            }
            if (! $collection->overview_locked && array_key_exists('overview', $payload)) {
                $collection->overview = $payload['overview'];
            }

            $collection->tmdb_snapshot = $payload;
            $collection->tmdb_synced_at = $this->clock->now();
            $collection->save();

            if ($collection->structure_locked || $suggestNewPartsOnly) {
                // Never silently insert new parts when locked; when unlocked we still only
                // refresh positions for movies already in the family catalog.
                $this->refreshTmdbPositions($collection, $payload['parts'] ?? []);

                return $collection->fresh(['items.movie']);
            }

            return $collection->fresh(['items.movie']);
        });
    }

    /**
     * Ensure a TMDB-backed collection exists and link a family movie into it.
     * Does not invent series from title similarity.
     */
    public function linkMovieFromTmdb(Movie $movie, string $collectionExternalId, ?string $name = null, ?int $storyPosition = null): MovieCollection
    {
        return DB::transaction(function () use ($movie, $collectionExternalId, $name, $storyPosition) {
            $collection = MovieCollection::query()->firstOrCreate(
                [
                    'family_id' => $movie->family_id,
                    'source' => 'tmdb',
                    'external_id' => $collectionExternalId,
                ],
                [
                    'name' => $name ?: 'TMDB Collection '.$collectionExternalId,
                    'sort_mode' => CollectionSortMode::Story->value,
                ],
            );

            if ($collection->structure_locked) {
                $existing = MovieCollectionItem::query()->where('movie_id', $movie->id)->first();
                if ($existing === null) {
                    return $collection->fresh(['items.movie']);
                }

                return $collection->fresh(['items.movie']);
            }

            $existing = MovieCollectionItem::query()->where('movie_id', $movie->id)->first();
            if ($existing !== null && $existing->collection_id !== $collection->id) {
                return $collection->fresh(['items.movie']);
            }

            if ($existing === null) {
                $releasePos = $movie->release_year;
                MovieCollectionItem::query()->create([
                    'family_id' => $movie->family_id,
                    'collection_id' => $collection->id,
                    'movie_id' => $movie->id,
                    'position_story' => $storyPosition,
                    'position_release' => $releasePos,
                    'position_custom' => $storyPosition ?? $releasePos,
                    'membership_source' => 'tmdb',
                ]);
            }

            $this->refreshCount($collection);

            return $collection->fresh(['items.movie']);
        });
    }

    public function fetchAndApplyTmdb(MovieCollection $collection): MovieCollection
    {
        if ($collection->source !== 'tmdb' || $collection->external_id === null || ! $this->tmdb->isConfigured()) {
            return $collection;
        }

        $payload = $this->tmdb->collection($collection->external_id);
        if ($payload === null) {
            return $collection;
        }

        return $this->applyTmdbSnapshot($collection, $payload);
    }

    public function setPreference(int $familyId, int $collectionId, ?int $childId, string $kind, ?string $note = null): MovieCollectionPreference
    {
        return MovieCollectionPreference::query()->updateOrCreate(
            [
                'collection_id' => $collectionId,
                'child_id' => $childId,
                'kind' => $kind,
            ],
            [
                'family_id' => $familyId,
                'note' => $note,
            ],
        );
    }

    /**
     * Soft-delete a series. Membership rows are removed; movies and watch/reward data stay.
     */
    public function destroyCollection(MovieCollection $collection): void
    {
        DB::transaction(function () use ($collection) {
            MovieCollectionItem::query()->where('collection_id', $collection->id)->delete();
            MovieCollectionPreference::query()->where('collection_id', $collection->id)->delete();
            $collection->movie_count = 0;
            $collection->save();
            $collection->delete();
        });
    }

    /**
     * Soft-delete a movie. Detaches series membership; keeps library/progress/reward history.
     */
    public function destroyMovie(Movie $movie): void
    {
        DB::transaction(function () use ($movie) {
            $item = MovieCollectionItem::query()->where('movie_id', $movie->id)->first();
            $collectionId = $item?->collection_id;
            $item?->delete();
            if ($collectionId !== null) {
                $collection = MovieCollection::query()->find($collectionId);
                if ($collection !== null) {
                    $this->refreshCount($collection);
                }
            }
            $movie->delete();
        });
    }

    /**
     * Parent catalog: fold into collection cards when a series has 2+ members.
     *
     * @param  Collection<int, Movie>  $movies
     * @return list<array<string, mixed>>
     */
    public function groupForParent(Collection $movies): array
    {
        $movieIds = $movies->pluck('id')->all();
        $items = MovieCollectionItem::query()
            ->whereIn('movie_id', $movieIds)
            ->with('collection')
            ->get()
            ->keyBy('movie_id');

        $byCollection = [];
        $singles = [];

        foreach ($movies as $movie) {
            $item = $items->get($movie->id);
            if ($item === null || $item->collection === null) {
                $singles[] = ['kind' => 'movie', 'movie' => $movie];

                continue;
            }
            $cid = $item->collection_id;
            $byCollection[$cid]['collection'] = $item->collection;
            $byCollection[$cid]['movies'][] = ['movie' => $movie, 'item' => $item];
        }

        $out = [];
        foreach ($byCollection as $group) {
            /** @var MovieCollection $collection */
            $collection = $group['collection'];
            $members = $group['movies'];
            if (count($members) < 2) {
                $out[] = ['kind' => 'movie', 'movie' => $members[0]['movie']];

                continue;
            }
            usort($members, fn ($a, $b) => $this->compareItems($collection, $a['item'], $b['item']));
            $out[] = [
                'kind' => 'collection',
                'collection' => $this->serializeParentCollection($collection, array_map(fn ($m) => $m['movie'], $members)),
                'movies' => array_map(fn ($m) => $m['movie'], $members),
            ];
        }

        return array_merge($out, $singles);
    }

    /**
     * Kid catalog: only visible movies; fold only when ≥2 visible in same series.
     * Never exposes hidden member titles/counts/posters.
     *
     * @return list<array<string, mixed>>
     */
    public function groupForKid(ChildProfile $child, ?string $search = null): array
    {
        $query = $this->visibility->visibleMoviesQuery($child);
        if (is_string($search) && $search !== '') {
            $query->where('movies.title_zh', 'like', '%'.$search.'%');
        }
        $movies = $query->orderBy('movies.title_zh')->get();

        return $this->groupVisibleMoviesForKid($child, $movies);
    }

    /**
     * @param  Collection<int, Movie>  $movies
     * @return list<array<string, mixed>>
     */
    public function groupVisibleMoviesForKid(ChildProfile $child, Collection $movies): array
    {
        $movieIds = $movies->pluck('id')->all();
        $items = MovieCollectionItem::query()
            ->whereIn('movie_id', $movieIds)
            ->with('collection')
            ->get()
            ->keyBy('movie_id');

        $libraries = ChildMovieLibrary::query()
            ->where('child_id', $child->id)
            ->whereIn('movie_id', $movieIds)
            ->with('progress')
            ->get()
            ->keyBy('movie_id');

        $byCollection = [];
        $singles = [];

        foreach ($movies as $movie) {
            $item = $items->get($movie->id);
            if ($item === null || $item->collection === null || $item->collection->trashed()) {
                $singles[] = [
                    'kind' => 'movie',
                    'movie' => $this->serializeKidMovie($movie, $libraries->get($movie->id)),
                ];

                continue;
            }
            $cid = $item->collection_id;
            $byCollection[$cid]['collection'] = $item->collection;
            $byCollection[$cid]['entries'][] = ['movie' => $movie, 'item' => $item];
        }

        $out = [];
        foreach ($byCollection as $group) {
            /** @var MovieCollection $collection */
            $collection = $group['collection'];
            $entries = $group['entries'];
            if (count($entries) < 2) {
                $only = $entries[0];
                $out[] = [
                    'kind' => 'movie',
                    'movie' => $this->serializeKidMovie($only['movie'], $libraries->get($only['movie']->id)),
                ];

                continue;
            }

            usort($entries, fn ($a, $b) => $this->compareItems($collection, $a['item'], $b['item']));
            $visibleMovies = array_map(fn ($e) => $e['movie'], $entries);
            $out[] = [
                'kind' => 'collection',
                'collection' => $this->serializeKidCollection($child, $collection, $visibleMovies, $libraries),
                'movies' => array_map(
                    fn ($e) => $this->serializeKidMovie($e['movie'], $libraries->get($e['movie']->id), $e['item']),
                    $entries,
                ),
            ];
        }

        return array_merge($out, $singles);
    }

    public function kidCollectionDetail(ChildProfile $child, int $collectionId): array
    {
        $collection = MovieCollection::query()
            ->where('family_id', $child->family_id)
            ->findOrFail($collectionId);

        $visibleIds = $this->visibility->visibleMoviesQuery($child)->pluck('movies.id')->all();
        $items = MovieCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->whereIn('movie_id', $visibleIds)
            ->with('movie')
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        // Single visible member: do not expose a series shell that implies more parts.
        if ($items->count() === 1) {
            abort(404);
        }

        $movies = $items->map(fn (MovieCollectionItem $i) => $i->movie)->filter();
        $libraries = ChildMovieLibrary::query()
            ->where('child_id', $child->id)
            ->whereIn('movie_id', $movies->pluck('id'))
            ->with('progress')
            ->get()
            ->keyBy('movie_id');

        $sorted = $items->sort(fn ($a, $b) => $this->compareItems($collection, $a, $b))->values();

        return [
            'kind' => 'collection',
            'collection' => $this->serializeKidCollection($child, $collection, $movies, $libraries),
            'movies' => $sorted->map(
                fn (MovieCollectionItem $item) => $this->serializeKidMovie($item->movie, $libraries->get($item->movie_id), $item)
            )->all(),
        ];
    }

    /**
     * @param  list<array{movie_id:int, position_story?:int|null, position_release?:int|null, position_custom?:int|null}>  $items
     */
    private function replaceItems(MovieCollection $collection, array $items, string $membershipSource, bool $lockStructure): void
    {
        $movieIds = array_map(fn ($row) => (int) $row['movie_id'], $items);
        $movies = Movie::query()
            ->where('family_id', $collection->family_id)
            ->whereIn('id', $movieIds)
            ->get()
            ->keyBy('id');

        if ($movies->count() !== count(array_unique($movieIds))) {
            throw new InvalidArgumentException('invalid movie_ids for family');
        }

        $previousCollectionIds = MovieCollectionItem::query()
            ->whereIn('movie_id', $movieIds)
            ->pluck('collection_id')
            ->unique()
            ->all();

        MovieCollectionItem::query()->where('collection_id', $collection->id)->delete();
        MovieCollectionItem::query()->whereIn('movie_id', $movieIds)->delete();

        $index = 1;
        foreach ($items as $row) {
            $movie = $movies->get((int) $row['movie_id']);
            MovieCollectionItem::query()->create([
                'family_id' => $collection->family_id,
                'collection_id' => $collection->id,
                'movie_id' => $movie->id,
                'position_story' => $row['position_story'] ?? $index,
                'position_release' => $row['position_release'] ?? $movie->release_year,
                'position_custom' => $row['position_custom'] ?? $index,
                'membership_source' => $membershipSource,
            ]);
            $index++;
        }

        if ($lockStructure) {
            $collection->structure_locked = true;
        }
        $this->refreshCount($collection);

        foreach ($previousCollectionIds as $cid) {
            if ((int) $cid === (int) $collection->id) {
                continue;
            }
            $other = MovieCollection::query()->find($cid);
            if ($other !== null) {
                $this->refreshCount($other);
            }
        }
    }

    /**
     * @param  list<array{external_id:string, release_year?:int|null}>  $parts
     */
    private function refreshTmdbPositions(MovieCollection $collection, array $parts): void
    {
        if ($parts === []) {
            return;
        }

        $externalOrder = [];
        foreach ($parts as $i => $part) {
            $externalOrder[(string) $part['external_id']] = [
                'story' => $i + 1,
                'release' => $part['release_year'] ?? null,
            ];
        }

        $items = MovieCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->with('movie')
            ->get();

        foreach ($items as $item) {
            $movie = $item->movie;
            if ($movie === null || $movie->source !== 'tmdb' || $movie->external_id === null) {
                continue;
            }
            $meta = $externalOrder[$movie->external_id] ?? null;
            if ($meta === null) {
                continue;
            }
            if (! $collection->structure_locked) {
                $item->position_story = $meta['story'];
                $item->position_release = $meta['release'] ?? $movie->release_year;
                $item->save();
            }
        }
    }

    private function refreshCount(MovieCollection $collection): void
    {
        $collection->movie_count = MovieCollectionItem::query()->where('collection_id', $collection->id)->count();
        $collection->save();
    }

    private function compareItems(MovieCollection $collection, MovieCollectionItem $a, MovieCollectionItem $b): int
    {
        $col = $collection->positionColumn();
        $av = $a->{$col};
        $bv = $b->{$col};
        if ($av === null && $bv === null) {
            return $a->movie_id <=> $b->movie_id;
        }
        if ($av === null) {
            return 1;
        }
        if ($bv === null) {
            return -1;
        }

        return $av <=> $bv ?: $a->movie_id <=> $b->movie_id;
    }

    /**
     * @param  list<Movie>  $members
     * @return array<string, mixed>
     */
    private function serializeParentCollection(MovieCollection $collection, array $members): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'overview' => $collection->overview,
            'sort_mode' => $collection->sort_mode instanceof CollectionSortMode
                ? $collection->sort_mode->value
                : (string) $collection->sort_mode,
            'movie_count' => $collection->movie_count,
            'structure_locked' => $collection->structure_locked,
            'member_count_in_view' => count($members),
        ];
    }

    /**
     * @param  Collection<int, Movie>|Collection<int, Movie>|list<Movie>  $visibleMovies
     * @param  Collection<int, ChildMovieLibrary>  $libraries
     * @return array<string, mixed>
     */
    private function serializeKidCollection(ChildProfile $child, MovieCollection $collection, $visibleMovies, Collection $libraries): array
    {
        $list = $visibleMovies instanceof Collection ? $visibleMovies->values() : collect($visibleMovies)->values();
        $visibleCount = $list->count();
        $watched = 0;
        $completed = 0;
        $nextId = null;

        foreach ($list as $movie) {
            $lib = $libraries->get($movie->id);
            $status = $lib?->progress?->status;
            if ($status !== null && $status !== 'NOT_STARTED') {
                $watched++;
            }
            if ($status === 'COMPLETED' || ($lib?->ever_completed ?? false)) {
                $completed++;
            }
        }

        // Next age-appropriate approved movie = first not completed in sort order.
        $items = MovieCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->whereIn('movie_id', $list->pluck('id'))
            ->get()
            ->keyBy('movie_id');

        $ordered = $list->sort(function (Movie $a, Movie $b) use ($collection, $items) {
            $ia = $items->get($a->id);
            $ib = $items->get($b->id);
            if ($ia === null || $ib === null) {
                return $a->id <=> $b->id;
            }

            return $this->compareItems($collection, $ia, $ib);
        })->values();

        foreach ($ordered as $movie) {
            $lib = $libraries->get($movie->id);
            $status = $lib?->progress?->status;
            if ($status !== 'COMPLETED' && ! ($lib?->ever_completed ?? false)) {
                $nextId = $movie->id;
                break;
            }
        }

        return [
            'id' => $collection->id,
            'name' => $collection->name,
            // Kid-safe stats only — never expose full movie_count.
            'visible_count' => $visibleCount,
            'watched_count' => $watched,
            'completed_count' => $completed,
            'progress_ratio' => $visibleCount > 0 ? round($completed / $visibleCount, 4) : 0,
            'next_movie_id' => $nextId,
            'sort_mode' => $collection->sort_mode instanceof CollectionSortMode
                ? $collection->sort_mode->value
                : (string) $collection->sort_mode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKidMovie(Movie $movie, ?ChildMovieLibrary $library = null, ?MovieCollectionItem $item = null): array
    {
        $row = [
            'id' => $movie->id,
            'title_zh' => $movie->title_zh,
            'original_title' => $movie->original_title,
            'runtime_minutes' => $movie->runtime_minutes,
            'overview' => $movie->overview,
            'release_year' => $movie->release_year,
            'poster_key' => $movie->poster_key,
        ];
        if ($item !== null) {
            $row['collection_position'] = [
                'story' => $item->position_story,
                'release' => $item->position_release,
                'custom' => $item->position_custom,
            ];
        }
        if ($library !== null) {
            $row['library'] = [
                'id' => $library->id,
                'shelf' => $library->shelf,
                'slot' => $library->slot,
                'progress' => $library->progress ? [
                    'position_seconds' => $library->progress->position_seconds,
                    'status' => $library->progress->status,
                ] : null,
            ];
        }

        return $row;
    }
}
