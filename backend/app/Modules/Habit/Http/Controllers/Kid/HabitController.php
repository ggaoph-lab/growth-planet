<?php

namespace App\Modules\Habit\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Habit\Models\Habit;
use App\Modules\Habit\Services\HabitCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HabitController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly HabitCheckInService $checkIns,
    ) {}

    public function index(): JsonResponse
    {
        $child = $this->context->child();
        $habits = Habit::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->where('is_active', true)
            ->get();

        return response()->json(['data' => $habits]);
    }

    public function storeRecord(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $child = $this->context->child();
        $habit = Habit::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);

        $record = $this->checkIns->checkIn($habit, $child, $this->context->family(), $data['note'] ?? null);

        return response()->json(['data' => $record], 201);
    }
}
