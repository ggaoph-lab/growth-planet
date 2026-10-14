<?php

namespace App\Modules\Schedule\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\Item;
use App\Modules\Schedule\Models\SubjectItemRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectItemRequirementController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
        ]);
        $child = $this->context->findChild($data['child_id']);

        $rows = SubjectItemRequirement::query()
            ->with('item.location')
            ->where('family_id', $this->context->familyId())
            ->where('child_id', $child->id)
            ->orderBy('subject')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:64'],
            'item_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        $child = $this->context->findChild($data['child_id']);
        $this->assertItem($child->id, $data['item_id']);

        $row = SubjectItemRequirement::query()->updateOrCreate(
            [
                'child_id' => $child->id,
                'subject' => $data['subject'],
                'item_id' => $data['item_id'],
            ],
            [
                'family_id' => $this->context->familyId(),
                'quantity' => $data['quantity'] ?? 1,
            ],
        );

        return response()->json(['data' => $row->load('item.location')], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = SubjectItemRequirement::query()
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
