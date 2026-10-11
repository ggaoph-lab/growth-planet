<?php

namespace App\Modules\Item\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Services\ItemEventRecorder;
use Illuminate\Http\JsonResponse;

class ItemStatusController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemEventRecorder $recorder,
    ) {}

    public function reportMissing(int $id): JsonResponse
    {
        $item = $this->findOwnItem($id);
        $event = $this->recorder->record(
            $item,
            ItemEventType::ReportedMissing,
            $this->context->user(),
            null,
            ItemStatus::TemporarilyMissing,
        );

        return response()->json(['data' => ['event' => $event, 'item' => $item->fresh('location')]], 201);
    }

    public function reportFound(int $id): JsonResponse
    {
        $item = $this->findOwnItem($id);
        $event = $this->recorder->record(
            $item,
            ItemEventType::Found,
            $this->context->user(),
            null,
            ItemStatus::Normal,
        );

        return response()->json(['data' => ['event' => $event, 'item' => $item->fresh('location')]], 201);
    }

    private function findOwnItem(int $id): Item
    {
        $child = $this->context->child();

        return Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);
    }
}
