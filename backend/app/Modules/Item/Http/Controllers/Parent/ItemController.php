<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemLocationSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemLocationSeeder $locations,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['nullable', 'integer'],
        ]);

        $query = Item::query()
            ->with('location')
            ->where('family_id', $this->context->familyId());

        if (isset($data['child_id'])) {
            $child = $this->context->findChild($data['child_id']);
            $query->where('child_id', $child->id);
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string'],
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ItemStatus::class)],
            'carry_daily' => ['nullable', 'boolean'],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $locationId = $data['location_id'] ?? $this->locations->defaultHomeLocationId($this->context->familyId());
        $this->assertLocation($locationId);

        $item = Item::query()->create([
            'family_id' => $this->context->familyId(),
            'child_id' => $child->id,
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'color' => $data['color'] ?? null,
            'note' => $data['note'] ?? null,
            'location_id' => $locationId,
            'location_confirmed_at' => $this->clock->now(),
            'status' => $data['status'] ?? ItemStatus::Normal,
            'carry_daily' => $data['carry_daily'] ?? false,
        ]);

        return response()->json(['data' => $item->load('location')], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $item = Item::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string'],
            'carry_daily' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(ItemStatus::class)],
        ]);
        $item->fill($data);
        $item->save();

        return response()->json(['data' => $item->load('location')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $item = Item::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $item->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function assertLocation(int $locationId): void
    {
        ItemLocation::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($locationId);
    }
}
