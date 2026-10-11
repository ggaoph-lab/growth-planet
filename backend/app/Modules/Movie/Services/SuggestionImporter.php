<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieAgeReview;
use App\Modules\Movie\Models\MovieApproval;
use App\Modules\Movie\Models\MovieCollection;
use App\Modules\Movie\Models\MovieCollectionItem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SuggestionImporter
{
    public function __construct(
        private readonly Clock $clock,
        private readonly MovieCollectionService $collections,
        private readonly MovieWatchService $watch,
    ) {}

    /**
     * Import the chosen suggestion. If it belongs to a franchise, import every part and create the series.
     *
     * @return array{movies:list<Movie>, collection:MovieCollection|null}
     */
    public function adopt(int $familyId, int $userId, string $key, ?ChildProfile $child): array
    {
        $parts = BuiltinMovieSuggestions::importSet($key);
        if ($parts === []) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($familyId, $userId, $parts, $child) {
            $movies = [];
            foreach ($parts as $part) {
                $movies[] = $this->upsertMovie($familyId, $userId, $part, $child);
            }

            $seriesName = $parts[0]['series_name'] ?? null;
            $collection = null;
            if (is_string($seriesName) && count($movies) >= 2) {
                $ids = array_map(fn (Movie $movie) => $movie->id, $movies);
                $alreadyGrouped = MovieCollectionItem::query()->whereIn('movie_id', $ids)->exists();
                if (! $alreadyGrouped) {
                    $collection = $this->collections->createManual(
                        $familyId,
                        $seriesName,
                        null,
                        array_map(fn (Movie $movie, array $part) => [
                            'movie_id' => $movie->id,
                            'position_story' => $part['position'],
                            'position_release' => $movie->release_year,
                            'position_custom' => $part['position'],
                        ], $movies, $parts),
                        'story',
                    );
                }
            }

            return ['movies' => $movies, 'collection' => $collection];
        });
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function upsertMovie(int $familyId, int $userId, array $part, ?ChildProfile $child): Movie
    {
        $movie = Movie::withTrashed()
            ->where('family_id', $familyId)
            ->where('source', 'manual')
            ->where('external_id', $part['key'])
            ->first();

        if ($movie === null) {
            $movie = Movie::query()->create([
                'family_id' => $familyId,
                'source' => 'manual',
                'external_id' => $part['key'],
                'title_zh' => $part['title_zh'],
                'original_title' => $part['original_title'],
                'release_year' => $part['release_year'],
                'runtime_minutes' => $part['runtime_minutes'],
                'overview' => $part['overview'],
                'poster_key' => $part['key'],
                'data_completeness' => 'insufficient',
                'attribution' => 'builtin',
            ]);
        } elseif ($movie->trashed()) {
            $movie->restore();
        }

        $movie->poster_key = $part['key'];
        $movie->overview = $part['overview'];
        if ($movie->data_completeness !== 'complete' || $movie->current_review_id === null) {
            $review = MovieAgeReview::query()->create([
                'movie_id' => $movie->id,
                'safety_status' => MovieSafetyStatus::ApprovedSuitable->value,
                'min_age' => 6,
                'reason' => '系统推荐加入',
                'reviewer_id' => $userId,
                'created_at' => $this->clock->now(),
            ]);
            $movie->current_review_id = $review->id;
            $movie->data_completeness = 'complete';
        }
        $movie->save();

        if ($child !== null) {
            MovieApproval::query()->updateOrCreate(
                ['movie_id' => $movie->id, 'child_id' => $child->id],
                [
                    'visible' => true,
                    'approved_by' => $userId,
                    'approved_at' => $this->clock->now(),
                    'revoked_at' => null,
                ],
            );
            $this->watch->placeIfVisible($child, $movie->id);
        }

        return $movie->fresh();
    }
}
