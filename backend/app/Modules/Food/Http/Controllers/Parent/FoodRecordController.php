<?php

namespace App\Modules\Food\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Food\Models\Food;
use App\Modules\Food\Models\FoodExplorationRecord;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodRecordController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = FoodExplorationRecord::query()
            ->with('food')
            ->where('family_id', $this->context->familyId());

        if (isset($data['child_id'])) {
            $child = $this->context->findChild($data['child_id']);
            $query->where('child_id', $child->id);
        }
        if (isset($data['from'])) {
            $query->whereDate('record_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('record_date', '<=', $data['to']);
        }

        return response()->json(['data' => $query->orderByDesc('record_date')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedRecord($request);
        $child = $this->context->findChild($data['child_id']);
        Food::query()->where('family_id', $this->context->familyId())->findOrFail($data['food_id']);

        $record = FoodExplorationRecord::query()->create([
            'family_id' => $this->context->familyId(),
            'child_id' => $child->id,
            'food_id' => $data['food_id'],
            'record_date' => $data['record_date'] ?? $this->clock->today($this->context->family()->timezone)->toDateString(),
            'looked' => $data['looked'] ?? false,
            'smelled' => $data['smelled'] ?? false,
            'touched' => $data['touched'] ?? false,
            'tasted_voluntarily' => $data['tasted_voluntarily'] ?? false,
            'ate' => $data['ate'] ?? false,
            'liking' => $data['liking'] ?? null,
            'said_tasty' => $data['said_tasty'] ?? null,
            'willing_again' => $data['willing_again'] ?? null,
            'child_words' => $data['child_words'] ?? null,
            'parent_observation' => $data['parent_observation'] ?? null,
            'recorded_by' => $this->context->user()->id,
        ]);

        return response()->json(['data' => $record->load('food')], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRecord(Request $request): array
    {
        return $request->validate([
            'child_id' => ['required', 'integer'],
            'food_id' => ['required', 'integer'],
            'record_date' => ['nullable', 'date'],
            'looked' => ['nullable', 'boolean'],
            'smelled' => ['nullable', 'boolean'],
            'touched' => ['nullable', 'boolean'],
            'tasted_voluntarily' => ['nullable', 'boolean'],
            'ate' => ['nullable', 'boolean'],
            'liking' => ['nullable', 'integer', 'min:1', 'max:5'],
            'said_tasty' => ['nullable', 'boolean'],
            'willing_again' => ['nullable', 'boolean'],
            'child_words' => ['nullable', 'string'],
            'parent_observation' => ['nullable', 'string'],
        ]);
    }
}
