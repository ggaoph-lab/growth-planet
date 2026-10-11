<?php

namespace App\Modules\Movie\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Enums\MovieSlot;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Movie\Models\MovieProgress;
use App\Modules\Movie\Models\MovieViewing;
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

    /**
     * Make sure every shelf row has a first pass, so the kid can drag a bar immediately.
     */
    public function ensureInitialViewings(ChildProfile $child): void
    {
        $libraries = ChildMovieLibrary::query()
            ->where('child_id', $child->id)
            ->with(['progress', 'movie', 'viewings'])
            ->get();

        foreach ($libraries as $library) {
            if ($library->viewings->isEmpty()) {
                $this->ensureInitialViewing($library);
            }
        }
    }

    /**
     * Move a pass to any percent, including backward over progress that was already saved.
     *
     * @return array{viewings: list<array<string, mixed>>, reward: array{xp: int, coins: int}|null}
     */
    public function saveViewing(ChildProfile $child, ChildMovieLibrary $library, ?int $viewingId, int $percent, ?string $language): array
    {
        return DB::transaction(function () use ($library, $viewingId, $percent, $language) {
            $viewing = $viewingId === null
                ? $this->ensureInitialViewing($library)
                : $this->findViewing($library, $viewingId);

            $this->applyPosition($viewing, $percent, $language);
            $this->syncHeadline($library);

            return $this->viewingPayload($library, null);
        });
    }

    /**
     * @return array{viewings: list<array<string, mixed>>, reward: array{xp: int, coins: int}|null}
     */
    public function addViewing(ChildProfile $child, ChildMovieLibrary $library, string $language): array
    {
        if (! in_array($language, ['zh', 'en'], true)) {
            $language = 'zh';
        }

        return DB::transaction(function () use ($library, $language) {
            $this->ensureInitialViewing($library);
            $max = (int) MovieViewing::query()->where('library_id', $library->id)->max('sequence');
            if ($max >= 20) {
                throw new ApiException('LIMIT_REACHED', BiMsg::of('观看次数已经很多了', 'That is a lot of viewings'));
            }

            MovieViewing::query()->create([
                'library_id' => $library->id,
                'sequence' => $max + 1,
                'language' => $language,
                'position_percent' => 0,
                'status' => 'NOT_STARTED',
                'rewarded' => false,
            ]);
            $this->syncHeadline($library);

            return $this->viewingPayload($library, null);
        });
    }

    /**
     * Finish a pass once it has been dragged to at least 90%. The first four passes can pay out.
     *
     * @return array{viewings: list<array<string, mixed>>, reward: array{xp: int, coins: int}|null}
     */
    public function completeViewing(ChildProfile $child, ChildMovieLibrary $library, int $viewingId, int $percent): array
    {
        $percent = max(0, min(100, $percent));
        if ($percent < MovieViewing::COMPLETE_PERCENT) {
            throw new ApiException('NOT_READY', BiMsg::of('看到 90% 以上才能完成', 'Reach 90% before finishing'));
        }

        return DB::transaction(function () use ($child, $library, $viewingId, $percent) {
            $viewing = MovieViewing::query()
                ->where('library_id', $library->id)
                ->lockForUpdate()
                ->findOrFail($viewingId);

            $reward = null;
            if ($viewing->rewarded) {
                $viewing->save();
            } elseif ($viewing->status !== 'COMPLETED') {
                $viewing->position_percent = $percent;
                $viewing->status = 'COMPLETED';
                $viewing->completed_at = $this->clock->now();
                $viewing->save();
                $reward = $this->rewards->onViewingComplete($child, $library, $viewing);
            } else {
                $viewing->save();
            }

            $this->syncHeadline($library);

            return $this->viewingPayload($library, $reward);
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

    private function ensureInitialViewing(ChildMovieLibrary $library): MovieViewing
    {
        $existing = MovieViewing::query()->where('library_id', $library->id)->orderBy('sequence')->first();
        if ($existing !== null) {
            return $existing;
        }

        $library->loadMissing(['progress', 'movie']);
        $progress = $library->progress;
        $percent = 0;
        $status = 'NOT_STARTED';
        $rewarded = false;
        $completedAt = null;

        if ($progress !== null && $progress->status === 'COMPLETED') {
            $percent = 100;
            $status = 'COMPLETED';
            $rewarded = true;
            $completedAt = $progress->completed_at;
        } elseif ($progress !== null) {
            $runtime = $library->movie?->runtime_minutes;
            if ($runtime && $runtime > 0) {
                $percent = (int) min(100, (int) round(($progress->position_seconds ?? 0) / ($runtime * 60) * 100));
            }
            $status = $percent > 0 ? 'WATCHING' : 'NOT_STARTED';
        }

        return MovieViewing::query()->create([
            'library_id' => $library->id,
            'sequence' => 1,
            'language' => 'zh',
            'position_percent' => $percent,
            'status' => $status,
            'completed_at' => $completedAt,
            'rewarded' => $rewarded,
        ]);
    }

    private function findViewing(ChildMovieLibrary $library, int $viewingId): MovieViewing
    {
        return MovieViewing::query()
            ->where('library_id', $library->id)
            ->findOrFail($viewingId);
    }

    private function applyPosition(MovieViewing $viewing, int $percent, ?string $language): void
    {
        if ($viewing->rewarded) {
            return;
        }
        if ($language === 'zh' || $language === 'en') {
            $viewing->language = $language;
        }
        $viewing->position_percent = max(0, min(100, $percent));
        if ($viewing->status !== 'COMPLETED') {
            $viewing->status = $viewing->position_percent > 0 ? 'WATCHING' : 'NOT_STARTED';
        }
        $viewing->save();
    }

    private function syncHeadline(ChildMovieLibrary $library): void
    {
        $library->loadMissing('movie');
        $viewings = MovieViewing::query()->where('library_id', $library->id)->orderBy('sequence')->get();
        $latest = $viewings->last();
        if ($latest === null) {
            return;
        }

        $progress = $this->ensureProgress($library);
        $runtime = $library->movie?->runtime_minutes;
        $progress->position_seconds = $runtime
            ? (int) round($latest->position_percent / 100 * $runtime * 60)
            : 0;
        if ($runtime) {
            $progress->duration_seconds = $runtime * 60;
        }

        $anyCompleted = $viewings->contains(fn (MovieViewing $viewing) => $viewing->status === 'COMPLETED');
        if ($latest->status === 'COMPLETED') {
            $progress->status = 'COMPLETED';
            $progress->completed_at ??= $this->clock->now();
        } elseif ($latest->position_percent > 0) {
            $progress->status = 'WATCHING';
            $progress->first_started_at ??= $this->clock->now();
        } elseif ($anyCompleted) {
            $progress->status = 'COMPLETED';
        } else {
            $progress->status = 'NOT_STARTED';
        }
        $progress->last_updated_at = $this->clock->now();
        $progress->save();

        if ($anyCompleted) {
            $library->ever_completed = true;
            if ($latest->status === 'COMPLETED') {
                $library->shelf = 'completed';
                $library->slot = null;
                $library->slot_active_key = null;
            }
            $library->save();
        } elseif ($latest->position_percent > 0 && $library->shelf === 'want') {
            $library->shelf = 'watching';
            $library->save();
        }
    }

    /**
     * @param  array{xp: int, coins: int}|null  $reward
     * @return array{viewings: list<array<string, mixed>>, reward: array{xp: int, coins: int}|null}
     */
    private function viewingPayload(ChildMovieLibrary $library, ?array $reward): array
    {
        $viewings = MovieViewing::query()
            ->where('library_id', $library->id)
            ->orderBy('sequence')
            ->get();

        return [
            'viewings' => $viewings->map(fn (MovieViewing $viewing) => $viewing->toKidArray())->all(),
            'reward' => $reward,
        ];
    }
}
