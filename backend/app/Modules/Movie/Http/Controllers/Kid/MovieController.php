<?php

namespace App\Modules\Movie\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Movie\Enums\MovieSlot;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Movie\Models\MovieWatchSession;
use App\Modules\Movie\Services\ContentVisibility;
use App\Modules\Movie\Services\MovieCollectionService;
use App\Modules\Movie\Services\MovieWatchService;
use App\Modules\Movie\Services\PosterCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MovieController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ContentVisibility $visibility,
        private readonly MovieWatchService $watch,
        private readonly MovieCollectionService $collections,
        private readonly PosterCache $posters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $this->watch->ensureVisibleLibrary($child);
        $q = $request->query('q');
        $search = is_string($q) && $q !== '' ? $q : null;

        if ($request->boolean('flat')) {
            $query = $this->visibility->visibleMoviesQuery($child);
            if ($search !== null) {
                $query->where('movies.title_zh', 'like', '%'.$search.'%');
            }

            return response()->json(['data' => $query->orderBy('movies.title_zh')->get()]);
        }

        return response()->json(['data' => $this->collections->groupForKid($child, $search)]);
    }

    public function show(int $id): JsonResponse
    {
        $movie = $this->visibility->findVisibleMovie($this->context->child(), $id);

        return response()->json(['data' => $movie]);
    }

    public function poster(int $id): BinaryFileResponse
    {
        $movie = $this->visibility->findVisibleMovie($this->context->child(), $id);
        $path = $movie->poster_key ? $this->posters->ensure($movie->poster_key) : null;
        if ($path === null || ! is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function library(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $this->watch->ensureVisibleLibrary($child);
        $items = ChildMovieLibrary::query()
            ->where('child_id', $child->id)
            ->with(['movie', 'progress'])
            ->orderByDesc('updated_at')
            ->get();

        if ($request->boolean('grouped')) {
            $movies = $items->map(fn (ChildMovieLibrary $row) => $row->movie)->filter()->values();
            $visible = $this->visibility->visibleMoviesQuery($child)
                ->whereIn('movies.id', $movies->pluck('id'))
                ->get();

            return response()->json([
                'data' => [
                    'entries' => $items,
                    'grouped' => $this->collections->groupVisibleMoviesForKid($child, $visible),
                ],
            ]);
        }

        return response()->json(['data' => $items]);
    }

    public function addLibrary(int $movieId): JsonResponse
    {
        $entry = $this->watch->addToLibrary($this->context->child(), $movieId);

        return response()->json(['data' => $entry->load(['movie', 'progress'])], 201);
    }

    public function assignSlot(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['slot' => ['required', 'in:NEW_EXPLORATION,FAMILIAR']]);
        $library = $this->findOwnLibrary($id);

        $entry = $this->watch->assignSlot(
            $this->context->child(),
            $library,
            MovieSlot::from($data['slot']),
        );

        return response()->json(['data' => $entry]);
    }

    public function startSession(Request $request, int $id): JsonResponse
    {
        $library = $this->findOwnLibrary($id);
        $data = $request->validate(['position_start' => ['nullable', 'integer', 'min:0']]);
        $session = $this->watch->startSession($this->context->child(), $library, $data['position_start'] ?? 0);

        return response()->json(['data' => $session], 201);
    }

    public function stopSession(Request $request, int $id): JsonResponse
    {
        $library = $this->findOwnLibrary($id);
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'position_end' => ['required', 'integer', 'min:0'],
        ]);

        $session = MovieWatchSession::query()
            ->where('library_id', $library->id)
            ->where('child_id', $this->context->child()->id)
            ->findOrFail($data['session_id']);

        $stopped = $this->watch->stopSession($this->context->child(), $session, $data['position_end']);

        return response()->json(['data' => $stopped]);
    }

    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $library = $this->findOwnLibrary($id);
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0']]);
        $progress = $this->watch->updatePosition($this->context->child(), $library, $data['position_seconds']);

        return response()->json(['data' => $progress]);
    }

    public function complete(int $id): JsonResponse
    {
        $library = $this->findOwnLibrary($id);
        $entry = $this->watch->complete($this->context->child(), $library);

        return response()->json(['data' => $entry]);
    }

    private function findOwnLibrary(int $id): ChildMovieLibrary
    {
        return ChildMovieLibrary::query()
            ->where('child_id', $this->context->child()->id)
            ->findOrFail($id);
    }
}
