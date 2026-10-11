<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Enums\MovieSlot;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Movie\Models\MovieProgress;
use App\Modules\Movie\Models\MovieWatchSession;
use App\Modules\ScreenTime\Services\ScreenTimeService;
use Illuminate\Support\Facades\DB;

class MovieWatchService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ContentVisibility $visibility,
        private readonly MovieRewardService $rewards,
        private readonly ScreenTimeService $screenTime,
    ) {}

    public function assignSlot(ChildProfile $child, ChildMovieLibrary $library, MovieSlot $slot): ChildMovieLibrary
    {
        $key = $child->id.':'.$slot->value;

        return DB::transaction(function () use ($child, $library, $slot, $key) {
            ChildMovieLibrary::query()
                ->where('child_id', $child->id)
                ->where('slot', $slot->value)
                ->where('id', '!=', $library->id)
                ->update(['slot' => null, 'slot_active_key' => null]);

            $library->slot = $slot->value;
            $library->slot_active_key = $key;
            $library->shelf = 'watching';
            $library->save();

            return $library->fresh(['movie', 'progress']);
        });
    }

    public function startSession(ChildProfile $child, ChildMovieLibrary $library, int $positionStart = 0): MovieWatchSession
    {
        $open = MovieWatchSession::query()
            ->where('library_id', $library->id)
            ->whereNull('ended_at')
            ->first();
        if ($open !== null) {
            return $open;
        }

        $progress = $this->ensureProgress($library);
        $firstStart = $progress->first_started_at === null;

        return DB::transaction(function () use ($child, $library, $positionStart, $progress, $firstStart) {
            if ($firstStart) {
                $progress->first_started_at = $this->clock->now();
                $progress->status = 'WATCHING';
                $progress->last_updated_at = $this->clock->now();
                $progress->save();
                $this->rewards->onFirstStart($child, $library);
            }

            return MovieWatchSession::query()->create([
                'library_id' => $library->id,
                'child_id' => $child->id,
                'started_at' => $this->clock->now(),
                'entry_method' => 'timer',
                'position_start' => $positionStart,
                'measured_seconds' => 0,
                'counted_seconds' => 0,
            ]);
        });
    }

    public function stopSession(ChildProfile $child, MovieWatchSession $session, int $positionEnd): MovieWatchSession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        $ended = $this->clock->now();
        $measured = max(0, $ended->getTimestamp() - $session->started_at->getTimestamp());

        return DB::transaction(function () use ($child, $session, $positionEnd, $ended, $measured) {
            $session->ended_at = $ended;
            $session->position_end = $positionEnd;
            $session->measured_seconds = $measured;
            $session->counted_seconds = $measured;
            $session->save();

            $library = $session->library;
            $tz = $child->family->timezone;
            [$weekStartUtc] = $this->clock->weekRange($tz);
            $weekStart = $weekStartUtc->setTimezone($tz)->toDateString();

            $this->screenTime->recordCounted(
                $child,
                'movie',
                'movie_watch_session',
                $session->id,
                $session->counted_seconds,
                $weekStart,
            );

            $progress = $this->ensureProgress($library);
            $progress->last_updated_at = $ended;
            $progress->save();

            return $session->fresh();
        });
    }

    public function updatePosition(ChildProfile $child, ChildMovieLibrary $library, int $positionSeconds): MovieProgress
    {
        $progress = $this->ensureProgress($library);
        $progress->position_seconds = max(0, $positionSeconds);
        $progress->last_updated_at = $this->clock->now();
        $progress->save();

        return $progress;
    }

    public function complete(ChildProfile $child, ChildMovieLibrary $library): ChildMovieLibrary
    {
        return DB::transaction(function () use ($child, $library) {
            $progress = $this->ensureProgress($library);
            $progress->status = 'COMPLETED';
            $progress->completed_at = $this->clock->now();
            $progress->save();

            $wasCompleted = $library->ever_completed;
            $library->shelf = 'completed';
            $library->ever_completed = true;
            $library->slot = null;
            $library->slot_active_key = null;
            $library->save();

            $this->rewards->onCompletion($child, $library);
            if (! $wasCompleted) {
                $previous = ChildMovieLibrary::query()
                    ->where('child_id', $child->id)
                    ->where('ever_completed', true)
                    ->where('id', '!=', $library->id)
                    ->orderByDesc('updated_at')
                    ->value('id');
                $this->rewards->onCrossMovie($child, $library, $previous ? (int) $previous : null);
            }

            return $library->fresh(['movie', 'progress']);
        });
    }

    public function addToLibrary(ChildProfile $child, int $movieId): ChildMovieLibrary
    {
        $this->visibility->findVisibleMovie($child, $movieId);

        return $this->placeOnShelf($child, $movieId);
    }

    /**
     * Parent approval is enough. Place the movie on the child's shelf when it is already visible.
     */
    public function placeIfVisible(ChildProfile $child, int $movieId): ?ChildMovieLibrary
    {
        $visible = $this->visibility->visibleMoviesQuery($child)
            ->where('movies.id', $movieId)
            ->exists();
        if (! $visible) {
            return null;
        }

        return $this->placeOnShelf($child, $movieId);
    }

    /**
     * Movies a parent already opened should appear without a second confirm on the kid screen.
     */
    public function ensureVisibleLibrary(ChildProfile $child): void
    {
        $movieIds = $this->visibility->visibleMoviesQuery($child)->pluck('movies.id');
        foreach ($movieIds as $movieId) {
            $this->placeOnShelf($child, (int) $movieId);
        }
    }

    private function placeOnShelf(ChildProfile $child, int $movieId): ChildMovieLibrary
    {
        return ChildMovieLibrary::query()->firstOrCreate(
            ['child_id' => $child->id, 'movie_id' => $movieId],
            ['family_id' => $child->family_id, 'shelf' => 'want'],
        );
    }

    private function ensureProgress(ChildMovieLibrary $library): MovieProgress
    {
        return MovieProgress::query()->firstOrCreate(
            ['library_id' => $library->id],
            ['status' => 'NOT_STARTED', 'position_seconds' => 0, 'lock_version' => 0],
        );
    }
}
