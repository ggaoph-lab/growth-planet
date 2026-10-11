<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Movie\Models\MovieRewardEvent;
use Illuminate\Support\Facades\DB;

class MovieRewardService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
    ) {}

    public function onFirstStart(ChildProfile $child, ChildMovieLibrary $library): void
    {
        $this->grantOnce($child, $library, 'FIRST_START', 'movie.first_start', 'movie:first_start:'.$library->id);
    }

    public function onCompletion(ChildProfile $child, ChildMovieLibrary $library): void
    {
        $this->grantOnce($child, $library, 'COMPLETION', 'movie.completion', 'movie:completion:'.$library->id);
    }

    public function onCrossMovie(ChildProfile $child, ChildMovieLibrary $library, ?int $previousLibraryId): void
    {
        $tz = $child->family->timezone;
        [$weekStartUtc] = $this->clock->weekRange($tz);
        $weekStart = $weekStartUtc->setTimezone($tz)->toDateString();
        $grantKey = 'movie:cross:'.$child->id.':'.$weekStart;

        DB::transaction(function () use ($child, $library, $previousLibraryId, $weekStart, $grantKey) {
            $exists = MovieRewardEvent::query()->where('library_id', $library->id)->where('kind', 'CROSS_MOVIE')->exists();
            if ($exists) {
                return;
            }

            $grant = $this->rewards->grant(new RewardIntent(
                familyId: $child->family_id,
                childId: $child->id,
                policyCode: 'movie.cross_movie',
                grantKey: $grantKey,
                sourceModule: SourceModule::Movie,
                sourceType: 'child_movie_library',
                sourceId: $library->id,
                occurredAt: $this->clock->now(),
            ));

            MovieRewardEvent::query()->create([
                'child_id' => $child->id,
                'library_id' => $library->id,
                'kind' => 'CROSS_MOVIE',
                'occurred_at' => $this->clock->now(),
                'week_start' => $weekStart,
                'previous_library_id' => $previousLibraryId,
                'reward_grant_id' => $grant->id,
            ]);
        });
    }

    private function grantOnce(
        ChildProfile $child,
        ChildMovieLibrary $library,
        string $kind,
        string $policyCode,
        string $grantKey,
    ): void {
        if (MovieRewardEvent::query()->where('library_id', $library->id)->where('kind', $kind)->exists()) {
            return;
        }

        $grant = $this->rewards->grant(new RewardIntent(
            familyId: $child->family_id,
            childId: $child->id,
            policyCode: $policyCode,
            grantKey: $grantKey,
            sourceModule: SourceModule::Movie,
            sourceType: 'child_movie_library',
            sourceId: $library->id,
            occurredAt: $this->clock->now(),
        ));

        MovieRewardEvent::query()->create([
            'child_id' => $child->id,
            'library_id' => $library->id,
            'kind' => $kind,
            'occurred_at' => $this->clock->now(),
            'reward_grant_id' => $grant->id,
        ]);
    }
}
