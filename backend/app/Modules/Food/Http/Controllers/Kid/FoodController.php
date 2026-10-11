<?php

namespace App\Modules\Food\Http\Controllers\Kid;

use App\Modules\Core\Support\Clock;
use App\Modules\Food\Models\Food;
use App\Modules\Food\Models\FoodExplorationRecord;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
    ) {}

    public function indexFoods(): JsonResponse
    {
        $foods = Food::query()
            ->where('family_id', $this->context->child()->family_id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $foods]);
    }

    public function storeRecord(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $data = $request->validate([
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
        ]);

        Food::query()->where('family_id', $child->family_id)->findOrFail($data['food_id']);

        $record = FoodExplorationRecord::query()->create([
            'family_id' => $child->family_id,
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
            'parent_observation' => null,
            'recorded_by' => $this->context->user()->id,
        ]);

        return response()->json(['data' => $record->load('food')], 201);
    }
}
