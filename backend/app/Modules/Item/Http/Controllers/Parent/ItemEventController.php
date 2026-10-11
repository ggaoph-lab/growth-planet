<?php

namespace App\Modules\Item\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemEvent;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemEventController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ItemEventRecorder $recorder,
    ) {}

    public function index(int $id): JsonResponse
    {
        $item = Item::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $events = ItemEvent::query()->where('item_id', $item->id)->orderByDesc('occurred_at')->limit(50)->get();

        return response()->json(['data' => $events]);
    }

    public function store(int $id, Request $request): JsonResponse
    {
        $item = Item::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'event_type' => ['required', Rule::enum(ItemEventType::class)],
            'to_location_id' => ['nullable', 'integer'],
            'to_status' => ['nullable', Rule::enum(ItemStatus::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($data['to_location_id'])) {
            ItemLocation::query()
                ->where('family_id', $this->context->familyId())
                ->findOrFail($data['to_location_id']);
        }

        $event = $this->recorder->record(
            $item,
            ItemEventType::from($data['event_type']),
            $this->context->user(),
            $data['to_location_id'] ?? null,
            isset($data['to_status']) ? ItemStatus::from($data['to_status']) : null,
            null,
            $data['note'] ?? null,
        );

        return response()->json(['data' => ['event' => $event, 'item' => $item->fresh('location')]], 201);
    }
}
