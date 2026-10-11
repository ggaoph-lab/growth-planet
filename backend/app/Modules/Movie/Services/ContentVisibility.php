<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use Illuminate\Database\Eloquent\Builder;

class ContentVisibility
{
    public function __construct(private readonly Clock $clock) {}

    /** @return Builder<Movie> */
    public function visibleMoviesQuery(ChildProfile $child): Builder
    {
        $age = $this->clock->ageToday($child->birth_date, $child->family->timezone);

        return Movie::query()
            ->where('movies.family_id', $child->family_id)
            ->where('movies.data_completeness', 'complete')
            ->join('movie_age_reviews as mar', 'mar.id', '=', 'movies.current_review_id')
            ->where('mar.safety_status', MovieSafetyStatus::ApprovedSuitable->value)
            ->where('mar.min_age', '<=', $age)
            ->where(function (Builder $q) use ($age) {
                $q->whereNull('mar.max_age')->orWhere('mar.max_age', '>=', $age);
            })
            ->join('movie_approvals as ma', function ($join) use ($child) {
                $join->on('ma.movie_id', '=', 'movies.id')
                    ->where('ma.child_id', $child->id)
                    ->where('ma.visible', true)
                    ->whereNull('ma.revoked_at');
            })
            ->select('movies.*');
    }

    public function findVisibleMovie(ChildProfile $child, int $movieId): Movie
    {
        return $this->visibleMoviesQuery($child)->where('movies.id', $movieId)->firstOrFail();
    }
}
