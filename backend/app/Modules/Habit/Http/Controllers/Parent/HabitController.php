<?php

namespace App\Modules\Habit\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Habit\Models\Habit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HabitController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $habits = Habit::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $habits]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:128'],
            'icon' => ['nullable', 'string', 'max:32'],
            'frequency' => ['nullable', 'string', 'max:16'],
            'target_per_period' => ['nullable', 'integer', 'min:1'],
            'xp_reward' => ['nullable', 'integer', 'min:0'],
            'coin_reward' => ['nullable', 'integer', 'min:0'],
            'point_reward' => ['nullable', 'integer', 'min:0'],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $habit = Habit::query()->create([
            'family_id' => $this->context->familyId(),
            'child_id' => $child->id,
            'title' => $data['title'],
            'icon' => $data['icon'] ?? null,
            'frequency' => $data['frequency'] ?? 'daily',
            'target_per_period' => $data['target_per_period'] ?? 1,
            'xp_reward' => $data['xp_reward'] ?? 1,
            'coin_reward' => $data['coin_reward'] ?? 1,
            'point_reward' => $data['point_reward'] ?? 1,
            'is_active' => true,
        ]);

        return response()->json(['data' => $habit], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $habit = Habit::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:128'],
            'icon' => ['nullable', 'string', 'max:32'],
            'xp_reward' => ['sometimes', 'integer', 'min:0'],
            'coin_reward' => ['sometimes', 'integer', 'min:0'],
            'point_reward' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $habit->fill($data);
        $habit->save();

        return response()->json(['data' => $habit]);
    }
}
