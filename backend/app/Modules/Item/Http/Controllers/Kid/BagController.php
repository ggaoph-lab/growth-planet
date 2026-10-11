<?php

namespace App\Modules\Item\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemChecklistService;
use Illuminate\Http\JsonResponse;

class BagController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemChecklistService $checklists,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $family = $this->context->family();

        $items = Item::query()
            ->with('location')
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->orderBy('name')
            ->get();

        $locations = ItemLocation::query()
            ->where('family_id', $child->family_id)
            ->orderBy('sort')
            ->get();

        $todayChecklists = $this->checklists->ensureTodayChecklists($child, $family);

        return response()->json([
            'data' => [
                'items' => $items,
                'locations' => $locations,
                'checklists' => $todayChecklists,
            ],
        ]);
    }
}
