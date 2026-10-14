<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemLocationSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemLocationController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemLocationSeeder $seeder,
    ) {}

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
            'kind' => ['required', Rule::in([
                ItemLocationKind::Home->value,
                ItemLocationKind::Bag->value,
            ])],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

        $kind = ItemLocationKind::from($data['kind']);
        $maxSort = (int) ItemLocation::query()
            ->where('family_id', $this->context->familyId())
            ->where('kind', $kind)
            ->max('sort');

        $location = ItemLocation::query()->create([
            'family_id' => $this->context->familyId(),
            'kind' => $kind,
            'name' => $data['name'],
            'is_system' => false,
            'sort' => $data['sort'] ?? ($maxSort + 1),
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

    public function destroy(int $id): JsonResponse
    {
        $familyId = $this->context->familyId();
        $location = ItemLocation::query()
            ->where('family_id', $familyId)
            ->findOrFail($id);

        if ($location->is_system) {
            throw ValidationException::withMessages([
                'location' => ['系统地点不能删除'],
            ]);
        }

        if (! in_array($location->kind, [ItemLocationKind::Home, ItemLocationKind::Bag], true)) {
            throw ValidationException::withMessages([
                'location' => ['只能删除家里或书包框'],
            ]);
        }

        $fallbackId = $this->seeder->defaultHomeLocationId($familyId);
        Item::query()
            ->where('family_id', $familyId)
            ->where('location_id', $location->id)
            ->update(['location_id' => $fallbackId]);

        $location->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
