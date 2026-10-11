<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Models\ItemLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemLocationController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $locations = ItemLocation::query()
            ->where('family_id', $this->context->familyId())
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'kind' => ['nullable', Rule::enum(ItemLocationKind::class)],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

        $location = ItemLocation::query()->create([
            'family_id' => $this->context->familyId(),
            'kind' => $data['kind'] ?? ItemLocationKind::Other,
            'name' => $data['name'],
            'is_system' => false,
            'sort' => $data['sort'] ?? 99,
        ]);

        return response()->json(['data' => $location], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $location = ItemLocation::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($location->is_system && isset($data['name'])) {
            unset($data['name']);
        }

        $location->fill($data);
        $location->save();

        return response()->json(['data' => $location]);
    }
}
