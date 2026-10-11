<?php

namespace App\Modules\Movie\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieApproval;
use App\Modules\Movie\Models\MovieCollection;
use App\Modules\Movie\Models\MovieCollectionItem;
use App\Modules\Movie\Services\MovieCollectionService;
use App\Modules\Movie\Services\MovieWatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MovieCollectionController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly MovieCollectionService $collections,
        private readonly MovieWatchService $watch,
        private readonly Clock $clock,
    ) {}

    public function index(): JsonResponse
    {
        $rows = MovieCollection::query()
            ->where('family_id', $this->context->familyId())
            ->with(['items.movie'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'overview' => ['nullable', 'string'],
            'sort_mode' => ['nullable', 'in:story,release,custom'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.movie_id' => ['required', 'integer'],
            'items.*.position_story' => ['nullable', 'integer'],
            'items.*.position_release' => ['nullable', 'integer'],
            'items.*.position_custom' => ['nullable', 'integer'],
        ]);

        try {
            $collection = $this->collections->createManual(
                $this->context->familyId(),
                $data['name'],
                $data['overview'] ?? null,
                $data['items'],
                $data['sort_mode'] ?? 'custom',
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_COLLECTION', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $collection], 201);
    }

    public function show(int $id): JsonResponse
    {
        $collection = $this->findOwned($id)->load(['items.movie']);

        return response()->json(['data' => $collection]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'overview' => ['nullable', 'string'],
            'sort_mode' => ['sometimes', 'in:story,release,custom'],
        ]);

        return response()->json(['data' => $this->collections->update($collection, $data)]);
    }

    public function syncItems(Request $request, int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.movie_id' => ['required', 'integer'],
            'items.*.position_story' => ['nullable', 'integer'],
            'items.*.position_release' => ['nullable', 'integer'],
            'items.*.position_custom' => ['nullable', 'integer'],
        ]);

        try {
            $updated = $this->collections->syncItems($collection, $data['items']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_COLLECTION', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $updated]);
    }

    public function merge(Request $request, int $id): JsonResponse
    {
        $target = $this->findOwned($id);
        $data = $request->validate([
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer'],
        ]);

        return response()->json(['data' => $this->collections->merge($target, $data['source_ids'])]);
    }

    public function split(Request $request, int $id): JsonResponse
    {
        $source = $this->findOwned($id);
        $data = $request->validate([
            'movie_ids' => ['required', 'array', 'min:1'],
            'movie_ids.*' => ['integer'],
            'name' => ['required', 'string', 'max:191'],
        ]);

        try {
            $created = $this->collections->split($source, $data['movie_ids'], $data['name']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_COLLECTION', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $created], 201);
    }

    public function syncTmdb(int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $updated = $this->collections->fetchAndApplyTmdb($collection);

        return response()->json(['data' => $updated]);
    }

    public function destroy(int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $this->collections->destroyCollection($collection);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function setPreference(Request $request, int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $data = $request->validate([
            'child_id' => ['nullable', 'integer'],
            'kind' => ['required', 'in:favorite,blocked,deprioritize'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($data['child_id'])) {
            $this->context->findChild($data['child_id']);
        }

        $pref = $this->collections->setPreference(
            $this->context->familyId(),
            $collection->id,
            $data['child_id'] ?? null,
            $data['kind'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $pref]);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $movies = Movie::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $grouped = $this->collections->groupForParent($movies);

        // One card per collection by default (already grouped).
        return response()->json(['data' => $grouped]);
    }

    /**
     * Batch approve members. Each movie must already be APPROVED_SUITABLE + complete.
     */
    public function batchApprovals(Request $request, int $id): JsonResponse
    {
        $collection = $this->findOwned($id);
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'movie_ids' => ['required', 'array', 'min:1'],
            'movie_ids.*' => ['integer'],
            'visible' => ['required', 'boolean'],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $memberIds = MovieCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->whereIn('movie_id', $data['movie_ids'])
            ->pluck('movie_id')
            ->all();

        if (count($memberIds) !== count(array_unique($data['movie_ids']))) {
            return response()->json(['error' => ['code' => 'INVALID_COLLECTION', 'message' => 'movies must belong to collection']], 422);
        }

        $approved = [];
        $rejected = [];

        DB::transaction(function () use ($memberIds, $child, $data, &$approved, &$rejected) {
            foreach ($memberIds as $movieId) {
                $movie = Movie::query()
                    ->where('family_id', $this->context->familyId())
                    ->with('currentReview')
                    ->findOrFail($movieId);

                $review = $movie->currentReview;
                $ok = $movie->data_completeness === 'complete'
                    && $review !== null
                    && $review->safety_status === MovieSafetyStatus::ApprovedSuitable;

                if (! $ok) {
                    $rejected[] = $movieId;

                    continue;
                }

                MovieApproval::query()->updateOrCreate(
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
                $approved[] = $movieId;
            }
        });

        return response()->json(['data' => ['approved' => $approved, 'rejected' => $rejected]]);
    }

    private function findOwned(int $id): MovieCollection
    {
        return MovieCollection::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
    }
}
