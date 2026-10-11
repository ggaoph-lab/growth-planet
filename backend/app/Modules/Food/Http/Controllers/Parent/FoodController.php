<?php

namespace App\Modules\Food\Http\Controllers\Parent;

use App\Modules\Food\Models\Food;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $foods = Food::query()
            ->where('family_id', $this->context->familyId())
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $foods]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'taste' => ['nullable', 'string', 'max:64'],
            'texture' => ['nullable', 'string', 'max:64'],
            'smell' => ['nullable', 'string', 'max:64'],
            'cooking_method' => ['nullable', 'string', 'max:64'],
        ]);

        $food = Food::query()->create([
            'family_id' => $this->context->familyId(),
            ...$data,
        ]);

        return response()->json(['data' => $food], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $food = Food::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'taste' => ['nullable', 'string', 'max:64'],
            'texture' => ['nullable', 'string', 'max:64'],
            'smell' => ['nullable', 'string', 'max:64'],
            'cooking_method' => ['nullable', 'string', 'max:64'],
        ]);
        $food->fill($data);
        $food->save();

        return response()->json(['data' => $food]);
    }
}
