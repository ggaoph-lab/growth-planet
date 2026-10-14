<?php

namespace App\Modules\Item\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemChecklistLineResult;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Models\ItemChecklistLine;
use App\Modules\Item\Services\ItemChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemChecklistController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemChecklistService $checklists,
    ) {}

    public function updateLine(int $id, int $lid, Request $request): JsonResponse
    {
        $child = $this->context->child();
        $checklist = ItemChecklist::query()->where('child_id', $child->id)->findOrFail($id);
        $line = ItemChecklistLine::query()
            ->where('item_checklist_id', $checklist->id)
            ->findOrFail($lid);

        Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($line->item_id);

        $data = $request->validate([
            'result' => ['required', Rule::enum(ItemChecklistLineResult::class)],
        ]);

        $updated = $this->checklists->updateLine(
            $line,
            ItemChecklistLineResult::from($data['result']),
            $this->context->user(),
            $child,
            $this->context->family(),
        );

        return response()->json(['data' => $updated]);
    }

    public function addBroughtItem(int $id, Request $request): JsonResponse
    {
        $child = $this->context->child();
        $checklist = ItemChecklist::query()->where('child_id', $child->id)->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, '物品名称不能为空');
        }

        $created = $this->checklists->addBroughtItem(
            $checklist,
            $name,
            $this->context->user(),
            $child,
        );

        return response()->json(['data' => $created], 201);
    }

    public function complete(int $id, Request $request): JsonResponse
    {
        $child = $this->context->child();
        $checklist = ItemChecklist::query()->where('child_id', $child->id)->findOrFail($id);
        $data = $request->validate([
            'placements' => ['sometimes', 'array'],
            'placements.*.item_id' => ['required', 'integer'],
            'placements.*.location_id' => ['nullable', 'integer'],
        ]);

        $result = $this->checklists->complete(
            $checklist,
            $child,
            $this->context->user(),
            $data['placements'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    public function reopen(int $id): JsonResponse
    {
        $child = $this->context->child();
        $checklist = ItemChecklist::query()->where('child_id', $child->id)->findOrFail($id);
        $reopened = $this->checklists->reopen($checklist, $child);

        return response()->json(['data' => ['checklist' => $reopened]]);
    }
}
