<?php

namespace App\Modules\Ielts\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ielts\Models\IeltsLearningState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persist Word Atlas (雅思星球) settings + FSRS progress per child so a browser
 * wipe or deploy cache clear does not reset the learning path.
 */
class IeltsLearningStateController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function show(): JsonResponse
    {
        $row = IeltsLearningState::query()
            ->where('child_id', $this->context->child()->id)
            ->first();

        return response()->json([
            'data' => $row ? [
                'settings' => $row->settings,
                'progress' => $row->progress,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'progress' => ['required', 'array'],
        ]);

        // Bound payload size — Word Atlas progress is JSON of card states, typically well under this.
        $encoded = json_encode(['settings' => $data['settings'], 'progress' => $data['progress']], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2_500_000) {
            return response()->json([
                'error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Learning state is too large to save'],
            ], 422);
        }

        $childId = $this->context->child()->id;
        $row = IeltsLearningState::query()->updateOrCreate(
            ['child_id' => $childId],
            [
                'settings' => $data['settings'],
                'progress' => $data['progress'],
            ],
        );

        return response()->json([
            'data' => [
                'settings' => $row->settings,
                'progress' => $row->progress,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
