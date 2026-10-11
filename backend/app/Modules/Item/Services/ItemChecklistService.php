<?php

namespace App\Modules\Item\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\User;
use App\Modules\Item\Enums\ItemChecklistKind;
use App\Modules\Item\Enums\ItemChecklistLineResult;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Models\ItemChecklistLine;
use App\Modules\Item\Models\ItemLocation;
use Illuminate\Support\Collection;

class ItemChecklistService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ItemEventRecorder $events,
        private readonly ItemLocationSeeder $locations,
    ) {}

    /**
     * @return Collection<int, ItemChecklist>
     */
    public function ensureTodayChecklists(ChildProfile $child, Family $family): Collection
    {
        $date = $this->clock->today($family->timezone)->toDateString();
        $kinds = [ItemChecklistKind::BeforeSchool, ItemChecklistKind::AfterSchool];
        $dailyItems = Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->where('carry_daily', true)
            ->whereNull('deleted_at')
            ->get();

        $checklists = collect();
        foreach ($kinds as $kind) {
            $checklist = ItemChecklist::query()->firstOrCreate(
                ['child_id' => $child->id, 'kind' => $kind, 'target_date' => $date],
                ['status' => 'open'],
            );
            foreach ($dailyItems as $item) {
                ItemChecklistLine::query()->firstOrCreate(
                    ['item_checklist_id' => $checklist->id, 'item_id' => $item->id],
                    ['result' => ItemChecklistLineResult::Pending],
                );
            }
            $checklists->push($checklist->load(['lines.item.location']));
        }

        return $checklists;
    }

    public function updateLine(
        ItemChecklistLine $line,
        ItemChecklistLineResult $result,
        User $actor,
        ChildProfile $child,
        Family $family,
    ): ItemChecklistLine {
        $checklist = $line->checklist;
        if ($checklist->child_id !== $child->id) {
            abort(404);
        }

        $item = Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($line->item_id);

        $line->result = $result;
        $line->actor_id = $actor->id;
        $line->checked_at = $this->clock->now();
        $line->save();

        $toLocation = null;
        $toStatus = null;
        if ($result === ItemChecklistLineResult::NotFound) {
            $toStatus = ItemStatus::TemporarilyMissing;
        } elseif ($result === ItemChecklistLineResult::LeftAtSchool) {
            $school = ItemLocation::query()
                ->where('family_id', $child->family_id)
                ->where('kind', ItemLocationKind::School)
                ->first();
            $toLocation = $school?->id ?? $this->locations->seedForFamily($child->family_id)[2]->id;
            $toStatus = ItemStatus::Normal;
        } elseif ($result === ItemChecklistLineResult::Present && $checklist->kind === ItemChecklistKind::BeforeSchool) {
            $bag = ItemLocation::query()
                ->where('family_id', $child->family_id)
                ->where('kind', ItemLocationKind::Bag)
                ->first();
            $toLocation = $bag?->id;
            $toStatus = ItemStatus::Normal;
        }

        $this->events->record(
            $item,
            ItemEventType::Checked,
            $actor,
            $toLocation,
            $toStatus,
            $line->id,
        );

        return $line->fresh(['item.location']);
    }
}
