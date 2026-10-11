<?php

namespace App\Modules\Item\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\User;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemEvent;

class ItemEventRecorder
{
    public function __construct(private readonly Clock $clock) {}

    public function record(
        Item $item,
        ItemEventType $type,
        ?User $actor,
        ?int $toLocationId = null,
        ?ItemStatus $toStatus = null,
        ?int $checklistLineId = null,
        ?string $note = null,
    ): ItemEvent {
        $fromLocation = $item->location_id;
        $fromStatus = $item->status;

        if ($toLocationId !== null && $toLocationId !== $fromLocation) {
            $item->location_id = $toLocationId;
            $item->location_confirmed_at = $this->clock->now();
        }
        if ($toStatus !== null && $toStatus !== $fromStatus) {
            $item->status = $toStatus;
        }
        if ($toLocationId !== null || $toStatus !== null) {
            $item->lock_version++;
            $item->save();
        }

        return ItemEvent::query()->create([
            'family_id' => $item->family_id,
            'item_id' => $item->id,
            'event_type' => $type,
            'from_location_id' => $fromLocation,
            'to_location_id' => $toLocationId ?? $fromLocation,
            'from_status' => $fromStatus,
            'to_status' => $toStatus ?? $fromStatus,
            'checklist_line_id' => $checklistLineId,
            'actor_id' => $actor?->id,
            'occurred_at' => $this->clock->now(),
            'note' => $note,
        ]);
    }
}
