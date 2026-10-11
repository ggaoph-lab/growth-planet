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
}
