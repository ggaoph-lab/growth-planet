<?php

namespace App\Modules\Schedule\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\Item;
use App\Modules\Schedule\Models\BagExtraRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BagExtraRequirementController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $child = $this->context->findChild($data['child_id']);

        $query = BagExtraRequirement::query()
            ->with('item.location')
            ->where('family_id', $this->context->familyId())
            ->where('child_id', $child->id);

        if (isset($data['from'])) {
            $query->whereDate('target_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('target_date', '<=', $data['to']);
        }

        return response()->json(['data' => $query->orderBy('target_date')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'target_date' => ['required', 'date'],
            'item_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:128'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $child = $this->context->findChild($data['child_id']);

        if (empty($data['item_id']) && empty($data['label'])) {
            throw ValidationException::withMessages([
                'item_id' => ['需指定物品或文字标签'],
            ]);
        }
        if (! empty($data['item_id'])) {
            $this->assertItem($child->id, (int) $data['item_id']);
        }

        $row = BagExtraRequirement::query()->create([
            'family_id' => $this->context->familyId(),
            'child_id' => $child->id,
            'target_date' => $data['target_date'],
            'item_id' => $data['item_id'] ?? null,
            'label' => $data['label'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $row->load('item.location')], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = BagExtraRequirement::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
        $row->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function assertItem(int $childId, int $itemId): void
    {
        Item::query()
            ->where('family_id', $this->context->familyId())
            ->where('child_id', $childId)
            ->findOrFail($itemId);
    }
}
