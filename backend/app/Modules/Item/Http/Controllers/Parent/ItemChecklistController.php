<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\ItemChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemChecklistController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $date = $data['date'] ?? $this->clock->today($this->context->family()->timezone)->toDateString();

        $checklists = ItemChecklist::query()
            ->with(['lines.item.location'])
            ->where('child_id', $child->id)
            ->whereDate('target_date', $date)
            ->get();

        return response()->json(['data' => $checklists]);
    }
}
