<?php

namespace App\Modules\Movie\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieAgeReview;
use App\Modules\Movie\Models\MovieApproval;
use App\Modules\Movie\Services\MovieCollectionService;
use App\Modules\Movie\Services\MovieWatchService;
use App\Modules\Movie\Services\PosterCache;
use App\Modules\Movie\Services\SuggestionImporter;
use App\Modules\Movie\Services\SuggestionWall;
use App\Modules\Movie\Services\TmdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MovieController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly TmdbClient $tmdb,
        private readonly MovieCollectionService $collections,
        private readonly PosterCache $posters,
        private readonly SuggestionImporter $importer,
        private readonly MovieWatchService $watch,
        private readonly SuggestionWall $wall,
    ) {}

    public function externalSearch(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');
        if ($q === '' || ! $this->tmdb->isConfigured()) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->tmdb->search($q)]);
    }

    /**
     * Poster-wall suggestions for the parent. Never shown on kid routes.
     * Curated shelf plus TMDB discover, without movies already in this family's library.
     */
    public function suggestions(): JsonResponse
    {
        $library = Movie::query()
            ->where('family_id', $this->context->familyId())
            ->get(['external_id', 'title_zh', 'original_title']);

        return response()->json(['data' => $this->wall->cards($library)]);
    }

    public function suggestionPoster(string $key): BinaryFileResponse
    {
        return $this->posterFile($this->posters->ensure($key));
    }

    public function poster(int $id): BinaryFileResponse
    {
        $movie = Movie::query()->where('family_id', $this->context->familyId())->findOrFail($id);

        return $this->posterFile($movie->poster_key ? $this->posters->ensure($movie->poster_key) : null);
    }

    public function adoptSuggestion(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['nullable', 'integer'],
        ]);
        $child = isset($data['child_id']) ? $this->context->findChild($data['child_id']) : null;
        $result = $this->importer->adopt($this->context->familyId(), $this->context->user()->id, $key, $child);

        return response()->json(['data' => [
            'movies' => $result['movies'],
            'collection' => $result['collection'],
        ]], 201);
    }

    private function posterFile(?string $path): BinaryFileResponse
    {
        if ($path === null || ! is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:manual,tmdb'],
            'external_id' => ['nullable', 'string', 'max:64'],
            'title_zh' => ['required', 'string', 'max:255'],
            'original_title' => ['nullable', 'string', 'max:255'],
            'release_year' => ['nullable', 'integer'],
            'runtime_minutes' => ['nullable', 'integer', 'min:1'],
            'overview' => ['nullable', 'string'],
            'genres' => ['nullable', 'array'],
            'collection_external_id' => ['nullable', 'string', 'max:64'],
            'collection_name' => ['nullable', 'string', 'max:191'],
            'collection_position_story' => ['nullable', 'integer', 'min:1'],
        ]);

        if (($data['source'] ?? '') === 'tmdb' && ! empty($data['external_id'])) {
            $data['overview'] = $this->tmdb->joinOverview(
                $data['overview'] ?? null,
                $this->tmdb->englishOverview((string) $data['external_id']),
            );
        }

        $movie = Movie::query()->create([
            'family_id' => $this->context->familyId(),
            'source' => $data['source'],
            'external_id' => $data['external_id'] ?? null,
            'title_zh' => $data['title_zh'],
            'original_title' => $data['original_title'] ?? null,
            'release_year' => $data['release_year'] ?? null,
            'runtime_minutes' => $data['runtime_minutes'] ?? null,
            'overview' => $data['overview'] ?? null,
            'genres' => $data['genres'] ?? null,
            'data_completeness' => 'insufficient',
        ]);

        // Prefer explicit TMDB collection id; otherwise resolve from TMDB movie detail (never title similarity).
        $collectionExternalId = $data['collection_external_id'] ?? null;
        $collectionName = $data['collection_name'] ?? null;
        if ($collectionExternalId === null && ($data['source'] ?? '') === 'tmdb' && ! empty($data['external_id'])) {
            $detail = $this->tmdb->movie((string) $data['external_id']);
            if ($detail !== null && $detail['belongs_to_collection'] !== null) {
                $collectionExternalId = $detail['belongs_to_collection']['external_id'];
                $collectionName = $detail['belongs_to_collection']['name'];
            }
        }

        if (is_string($collectionExternalId) && $collectionExternalId !== '') {
            $this->collections->linkMovieFromTmdb(
                $movie,
                $collectionExternalId,
                $collectionName,
                $data['collection_position_story'] ?? null,
            );
        }

        return response()->json(['data' => $movie->fresh(['collectionItem.collection'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $movie = Movie::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'title_zh' => ['sometimes', 'string', 'max:255'],
            'runtime_minutes' => ['nullable', 'integer', 'min:1'],
            'overview' => ['nullable', 'string'],
            'data_completeness' => ['sometimes', 'in:complete,insufficient'],
        ]);

        $movie->fill($data);
        $movie->save();

        return response()->json(['data' => $movie->fresh()]);
    }

    public function index(Request $request): JsonResponse
    {
        $movies = Movie::query()
            ->where('family_id', $this->context->familyId())
            ->when($request->query('q'), fn ($q, $term) => $q->where('title_zh', 'like', '%'.$term.'%'))
            ->with('collectionItem.collection')
            ->orderByDesc('id')
            ->get();

        if ($request->boolean('grouped')) {
            return response()->json(['data' => $this->collections->groupForParent($movies)]);
        }

        return response()->json(['data' => $movies]);
    }

    public function storeReview(Request $request, int $id): JsonResponse
    {
        $movie = Movie::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'safety_status' => ['required', 'string'],
            'min_age' => ['required', 'integer', 'min:0', 'max:18'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:18'],
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        return DB::transaction(function () use ($movie, $data) {
            $review = MovieAgeReview::query()->create([
                'movie_id' => $movie->id,
                'safety_status' => $data['safety_status'],
                'min_age' => $data['min_age'],
                'max_age' => $data['max_age'] ?? null,
                'reason' => $data['reason'] ?? null,
                'reviewer_id' => $this->context->user()->id,
                'created_at' => $this->clock->now(),
            ]);

            $movie->current_review_id = $review->id;
            if ($data['safety_status'] === MovieSafetyStatus::ApprovedSuitable->value && $movie->runtime_minutes) {
                $movie->data_completeness = 'complete';
            }
            $movie->save();

            return response()->json(['data' => $review], 201);
        });
    }

    public function upsertApproval(Request $request, int $id, int $childId): JsonResponse
    {
        $movie = Movie::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $child = $this->context->findChild($childId);

        $data = $request->validate(['visible' => ['required', 'boolean']]);

        $approval = MovieApproval::query()->updateOrCreate(
            ['movie_id' => $movie->id, 'child_id' => $child->id],
            [
                'visible' => $data['visible'],
                'approved_by' => $data['visible'] ? $this->context->user()->id : null,
                'approved_at' => $data['visible'] ? $this->clock->now() : null,
                'revoked_at' => $data['visible'] ? null : $this->clock->now(),
            ],
        );

        if ($data['visible']) {
            $this->watch->placeIfVisible($child, $movie->id);
        }

        return response()->json(['data' => $approval]);
    }

    public function destroy(int $id): JsonResponse
    {
        $movie = Movie::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $this->collections->destroyMovie($movie);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }
}
