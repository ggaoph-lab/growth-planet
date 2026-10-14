<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Services\ItemChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemChecklistController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemChecklistService $checklists,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $child = $this->context->findChild($data['child_id']);

        if (! isset($data['date'])) {
            return response()->json([
                'data' => $this->checklists->ensureTodayChecklists($child, $this->context->family())->values(),
            ]);
        }

        $checklists = ItemChecklist::query()
            ->with(['lines.item.location'])
            ->where('child_id', $child->id)
            ->whereDate('target_date', $data['date'])
            ->get();

        return response()->json(['data' => $checklists]);
    }
}
