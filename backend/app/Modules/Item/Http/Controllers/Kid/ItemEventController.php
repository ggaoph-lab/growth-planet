<?php

namespace App\Modules\Item\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemEvent;
use App\Modules\Item\Models\ItemLocation;
use Illuminate\Http\JsonResponse;

class ItemEventController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(int $id): JsonResponse
    {
        $child = $this->context->child();
        $item = Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);

        $events = ItemEvent::query()
            ->where('item_id', $item->id)
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get();

        $locationIds = $events
            ->flatMap(fn (ItemEvent $e) => [$e->from_location_id, $e->to_location_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $locations = ItemLocation::query()
            ->where('family_id', $child->family_id)
            ->whereIn('id', $locationIds)
            ->get()
            ->keyBy('id');

        $payload = $events->map(function (ItemEvent $event) use ($locations) {
            $from = $event->from_location_id ? $locations->get($event->from_location_id) : null;
            $to = $event->to_location_id ? $locations->get($event->to_location_id) : null;

            return [
                'id' => $event->id,
                'item_id' => $event->item_id,
                'event_type' => $event->event_type->value,
                'from_location_id' => $event->from_location_id,
                'to_location_id' => $event->to_location_id,
                'from_location' => $from ? ['id' => $from->id, 'name' => $from->name, 'kind' => $from->kind->value] : null,
                'to_location' => $to ? ['id' => $to->id, 'name' => $to->name, 'kind' => $to->kind->value] : null,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status?->value,
                'checklist_line_id' => $event->checklist_line_id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'note' => $event->note,
            ];
        });

        return response()->json([
            'data' => [
                'item' => $item->load('location'),
                'events' => $payload,
            ],
        ]);
    }
}
