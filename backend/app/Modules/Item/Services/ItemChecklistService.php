<?php

namespace App\Modules\Item\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\User;
use App\Modules\Item\Enums\ItemChecklistKind;
use App\Modules\Item\Enums\ItemChecklistLineResult;
use App\Modules\Item\Enums\ItemChecklistStatus;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Enums\ItemStatus;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Models\ItemChecklistLine;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Schedule\Models\BagExtraRequirement;
use App\Modules\Schedule\Models\SubjectItemRequirement;
use App\Modules\Schedule\Services\ScheduleResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ItemChecklistService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ItemEventRecorder $events,
        private readonly ItemLocationSeeder $locations,
        private readonly RewardGrantService $rewards,
        private readonly ScheduleResolver $scheduleResolver,
    ) {}

    /**
     * Active kid checklists:
     * - before_school → tomorrow (packed the night before)
     * - after_school → today (checked the same day after school)
     *
     * Driven by resolved school schedule + daily carry + subject/extra requirements.
     *
     * @return Collection<int, ItemChecklist>
     */
    public function ensureTodayChecklists(ChildProfile $child, Family $family): Collection
    {
        $today = $this->clock->today($family->timezone);
        $specs = [
            [ItemChecklistKind::BeforeSchool, $today->addDay()->toDateString()],
            [ItemChecklistKind::AfterSchool, $today->toDateString()],
        ];

        $schoolLocationId = $this->locationId($child->family_id, ItemLocationKind::School);
        $checklists = collect();

        foreach ($specs as [$kind, $date]) {
            $resolved = $this->scheduleResolver->resolve($child, $family, $date);

            $existing = ItemChecklist::query()
                ->where('child_id', $child->id)
                ->where('kind', $kind)
                ->whereDate('target_date', $date)
                ->first();

            if (! $resolved->isSchoolDay) {
                if ($existing !== null && $existing->status === ItemChecklistStatus::Open) {
                    $existing->status = ItemChecklistStatus::Cancelled;
                    $existing->generated_from = $resolved->toArray();
                    $existing->save();
                }

                continue;
            }

            $checklist = $existing ?? ItemChecklist::query()->create([
                'child_id' => $child->id,
                'kind' => $kind,
                'target_date' => $date,
                'status' => ItemChecklistStatus::Open,
            ]);

            if (! $checklist->isOpen() && $checklist->status !== ItemChecklistStatus::Cancelled) {
                $checklists->push($checklist->load(['lines.item.location']));

                continue;
            }

            if ($checklist->status === ItemChecklistStatus::Cancelled) {
                $checklist->status = ItemChecklistStatus::Open;
            }

            $candidates = $this->collectCandidateItems($child, $date, $resolved->subjects, $kind, $schoolLocationId);
            $this->syncChecklistLines($checklist, $candidates);
            $checklist->generated_from = $resolved->toArray();
            $checklist->save();

            $checklists->push($checklist->fresh(['lines.item.location']));
        }

        return $checklists;
    }

    /**
     * @param  list<string>  $subjects
     * @return array<int, list<string>> item_id => sources
     */
    private function collectCandidateItems(
        ChildProfile $child,
        string $date,
        array $subjects,
        ItemChecklistKind $kind,
        int $schoolLocationId,
    ): array {
        /** @var array<int, list<string>> $byItem */
        $byItem = [];

        $dailyItems = Item::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->where('carry_daily', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($dailyItems as $item) {
            if ($this->shouldSkipAtSchool($item, $kind, $schoolLocationId)) {
                continue;
            }
            $byItem[$item->id] = array_values(array_unique([
                ...($byItem[$item->id] ?? []),
                'daily',
            ]));
        }

        if ($subjects !== []) {
            $reqs = SubjectItemRequirement::query()
                ->where('child_id', $child->id)
                ->whereIn('subject', $subjects)
                ->with('item')
                ->get();

            foreach ($reqs as $req) {
                $item = $req->item;
                if ($item === null || $item->deleted_at !== null) {
                    continue;
                }
                if ($this->shouldSkipAtSchool($item, $kind, $schoolLocationId)) {
                    continue;
                }
                $byItem[$item->id] = array_values(array_unique([
                    ...($byItem[$item->id] ?? []),
                    'subject:'.$req->subject,
                ]));
            }
        }

        $extras = BagExtraRequirement::query()
            ->where('child_id', $child->id)
            ->whereDate('target_date', $date)
            ->with('item')
            ->get();

        foreach ($extras as $extra) {
            $itemId = $extra->item_id;
            if ($itemId === null && $extra->label) {
                $item = Item::query()->create([
                    'family_id' => $child->family_id,
                    'child_id' => $child->id,
                    'name' => $extra->label,
                    'location_id' => $this->locationId($child->family_id, ItemLocationKind::Home),
                    'location_confirmed_at' => $this->clock->now(),
                    'status' => ItemStatus::Normal,
                    'carry_daily' => false,
                    'note' => '临时加带',
                ]);
                $extra->item_id = $item->id;
                $extra->save();
                $itemId = $item->id;
            }
            if ($itemId === null) {
                continue;
            }
            $item = $extra->item ?? Item::query()->find($itemId);
            if ($item === null || $item->deleted_at !== null) {
                continue;
            }
            if ($this->shouldSkipAtSchool($item, $kind, $schoolLocationId)) {
                continue;
            }
            $byItem[$itemId] = array_values(array_unique([
                ...($byItem[$itemId] ?? []),
                'extra',
            ]));
        }

        return $byItem;
    }

    private function shouldSkipAtSchool(Item $item, ItemChecklistKind $kind, int $schoolLocationId): bool
    {
        return $kind === ItemChecklistKind::BeforeSchool
            && (int) $item->location_id === $schoolLocationId;
    }

    /**
     * @param  array<int, list<string>>  $candidates
     */
    private function syncChecklistLines(ItemChecklist $checklist, array $candidates): void
    {
        $checklist->loadMissing('lines');
        $existingByItem = $checklist->lines->keyBy('item_id');

        foreach ($candidates as $itemId => $sources) {
            $line = $existingByItem->get($itemId);
            if ($line === null) {
                ItemChecklistLine::query()->create([
                    'item_checklist_id' => $checklist->id,
                    'item_id' => $itemId,
                    'result' => ItemChecklistLineResult::Pending,
                    'sources' => $sources,
                ]);

                continue;
            }

            if ($line->result === ItemChecklistLineResult::NotNeeded) {
                $line->result = ItemChecklistLineResult::Pending;
                $line->sources = $sources;
                $line->save();

                continue;
            }

            $line->sources = $sources;
            $line->save();
        }

        foreach ($existingByItem as $itemId => $line) {
            if (isset($candidates[$itemId])) {
                continue;
            }
            if ($line->result === ItemChecklistLineResult::Pending) {
                $line->result = ItemChecklistLineResult::NotNeeded;
                $line->save();
            }
        }
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
        $this->assertOpen($checklist);

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
        $eventType = ItemEventType::Checked;
        if ($result === ItemChecklistLineResult::NotFound) {
            $toStatus = ItemStatus::TemporarilyMissing;
        } elseif ($result === ItemChecklistLineResult::LeftAtSchool) {
            $toLocation = $this->locationId($child->family_id, ItemLocationKind::School);
            $toStatus = ItemStatus::Normal;
        } elseif ($result === ItemChecklistLineResult::Present && $checklist->kind === ItemChecklistKind::BeforeSchool) {
            $toLocation = $this->locationId($child->family_id, ItemLocationKind::Bag);
            $toStatus = ItemStatus::Normal;
        } elseif ($result === ItemChecklistLineResult::Present && $checklist->kind === ItemChecklistKind::AfterSchool) {
            $toLocation = $this->locationId($child->family_id, ItemLocationKind::Home);
            $toStatus = ItemStatus::Normal;
            $eventType = ItemEventType::BroughtHome;
        }

        $this->events->record(
            $item,
            $eventType,
            $actor,
            $toLocation,
            $toStatus,
            $line->id,
        );

        return $line->fresh(['item.location']);
    }

    /**
     * Add a one-off item to an open checklist:
     * - after_school → brought home from school
     * - before_school → packed into the bag
     *
     * @return array{item: Item, line: ItemChecklistLine, checklist: ItemChecklist}
     */
    public function addBroughtItem(
        ItemChecklist $checklist,
        string $name,
        User $actor,
        ChildProfile $child,
    ): array {
        if ($checklist->child_id !== $child->id) {
            abort(404);
        }
        if (! in_array($checklist->kind, [ItemChecklistKind::AfterSchool, ItemChecklistKind::BeforeSchool], true)) {
            abort(404);
        }
        $this->assertOpen($checklist);

        $homeId = $this->locationId($child->family_id, ItemLocationKind::Home);
        $bagId = $this->locationId($child->family_id, ItemLocationKind::Bag);
        $schoolId = $this->locationId($child->family_id, ItemLocationKind::School);

        $fromLocationId = $checklist->kind === ItemChecklistKind::AfterSchool ? $schoolId : $homeId;
        $toLocationId = $checklist->kind === ItemChecklistKind::AfterSchool ? $homeId : $bagId;
        $eventType = $checklist->kind === ItemChecklistKind::AfterSchool
            ? ItemEventType::BroughtHome
            : ItemEventType::Checked;
        $note = $checklist->kind === ItemChecklistKind::AfterSchool ? '从学校带回' : '放入书包';

        $item = Item::query()->create([
            'family_id' => $child->family_id,
            'child_id' => $child->id,
            'name' => $name,
            'location_id' => $fromLocationId,
            'location_confirmed_at' => $this->clock->now(),
            'status' => ItemStatus::Normal,
            'carry_daily' => false,
        ]);

        $line = ItemChecklistLine::query()->create([
            'item_checklist_id' => $checklist->id,
            'item_id' => $item->id,
            'result' => ItemChecklistLineResult::Present,
            'actor_id' => $actor->id,
            'checked_at' => $this->clock->now(),
        ]);

        $this->events->record(
            $item,
            $eventType,
            $actor,
            $toLocationId,
            ItemStatus::Normal,
            $line->id,
            $note,
        );

        return [
            'item' => $item->fresh('location'),
            'line' => $line->fresh(['item.location']),
            'checklist' => $checklist->fresh(['lines.item.location']),
        ];
    }

    /**
     * Complete a checklist. When $placements is provided, apply drag-box destinations first:
     * location_id null = 「我的物品」→ default home; otherwise move to that location.
     *
     * @param  list<array{item_id: int, location_id: int|null}>|null  $placements
     * @return array{checklist: ItemChecklist, reward: array{xp: int, coins: int}|null}
     */
    public function complete(
        ItemChecklist $checklist,
        ChildProfile $child,
        User $actor,
        ?array $placements = null,
    ): array {
        if ($checklist->child_id !== $child->id) {
            abort(404);
        }
        if (! $checklist->isOpen()) {
            return [
                'checklist' => $checklist->load(['lines.item.location']),
                'reward' => null,
            ];
        }

        $checklist->loadMissing('lines');

        if ($placements !== null) {
            $this->applyPlacements($checklist, $child, $actor, $placements);
            $checklist->load('lines');
        }

        $activeLines = $checklist->lines->filter(
            fn (ItemChecklistLine $line) => $line->result !== ItemChecklistLineResult::NotNeeded,
        );

        $pending = $activeLines->contains(
            fn (ItemChecklistLine $line) => $line->result === ItemChecklistLineResult::Pending,
        );
        if ($pending) {
            throw ValidationException::withMessages([
                'checklist' => ['还有物品没有放到框里'],
            ]);
        }

        $hasIssues = $activeLines->contains(
            fn (ItemChecklistLine $line) => $line->result === ItemChecklistLineResult::NotFound,
        );

        $checklist->status = $hasIssues
            ? ItemChecklistStatus::CompletedWithIssues
            : ItemChecklistStatus::Completed;
        $checklist->save();

        return [
            'checklist' => $checklist->fresh(['lines.item.location']),
            'reward' => $this->grantCompletionReward($checklist, $child),
        ];
    }

    /**
     * Open a finished checklist again so the child can drag and complete once more.
     * Current item locations stay put. A second completion does not grant another reward.
     */
    public function reopen(ItemChecklist $checklist, ChildProfile $child): ItemChecklist
    {
        if ($checklist->child_id !== $child->id) {
            abort(404);
        }
        if ($checklist->status === ItemChecklistStatus::Cancelled) {
            abort(409, '休息日不能重新核对');
        }
        if ($checklist->isOpen()) {
            return $checklist->load(['lines.item.location']);
        }

        $checklist->status = ItemChecklistStatus::Open;
        $checklist->save();

        ItemChecklistLine::query()
            ->where('item_checklist_id', $checklist->id)
            ->where('result', '!=', ItemChecklistLineResult::NotNeeded->value)
            ->update([
                'result' => ItemChecklistLineResult::Pending->value,
                'actor_id' => null,
                'checked_at' => null,
            ]);

        return $checklist->fresh(['lines.item.location']);
    }

    /**
     * @param  list<array{item_id: int, location_id: int|null}>  $placements
     */
    private function applyPlacements(
        ItemChecklist $checklist,
        ChildProfile $child,
        User $actor,
        array $placements,
    ): void {
        $defaultHomeId = $this->locationId($child->family_id, ItemLocationKind::Home);
        $locationMap = ItemLocation::query()
            ->where('family_id', $child->family_id)
            ->get()
            ->keyBy('id');

        $byItem = [];
        foreach ($placements as $row) {
            $byItem[(int) $row['item_id']] = $row['location_id'] !== null
                ? (int) $row['location_id']
                : null;
        }

        foreach ($checklist->lines as $line) {
            if (! array_key_exists($line->item_id, $byItem)) {
                continue;
            }

            $rawLocationId = $byItem[$line->item_id];
            $toLocationId = $rawLocationId ?? $defaultHomeId;
            $location = $locationMap->get($toLocationId);
            if ($location === null) {
                throw ValidationException::withMessages([
                    'placements' => ['地点不存在'],
                ]);
            }

            $item = Item::query()
                ->where('family_id', $child->family_id)
                ->where('child_id', $child->id)
                ->findOrFail($line->item_id);

            $kind = $location->kind;
            $result = ItemChecklistLineResult::Present;
            $eventType = ItemEventType::Checked;
            $note = null;

            if ($kind === ItemLocationKind::School) {
                $result = ItemChecklistLineResult::LeftAtSchool;
                $eventType = ItemEventType::Checked;
                $note = '放在学校';
            } elseif ($kind === ItemLocationKind::Bag) {
                $result = ItemChecklistLineResult::Present;
                $eventType = ItemEventType::Checked;
                $note = '放入书包';
            } elseif ($checklist->kind === ItemChecklistKind::AfterSchool) {
                $result = ItemChecklistLineResult::Present;
                $eventType = ItemEventType::BroughtHome;
                $note = $rawLocationId === null ? '留在我的物品' : '带回家里';
            } else {
                // before_school: left in 我的物品 / 家里 → stay home, not packed
                $result = ItemChecklistLineResult::Present;
                $eventType = ItemEventType::Checked;
                $note = $rawLocationId === null ? '未放入书包' : '放在家里';
            }

            $line->result = $result;
            $line->actor_id = $actor->id;
            $line->checked_at = $this->clock->now();
            $line->save();

            if ((int) $item->location_id !== $toLocationId || $item->status !== ItemStatus::Normal) {
                $this->events->record(
                    $item,
                    $eventType,
                    $actor,
                    $toLocationId,
                    ItemStatus::Normal,
                    $line->id,
                    $note,
                );
            }
        }

        // Also move non-checklist items that were dragged (e.g. one-off belongings)
        foreach ($byItem as $itemId => $rawLocationId) {
            $onChecklist = $checklist->lines->contains(
                fn (ItemChecklistLine $line) => $line->item_id === $itemId,
            );
            if ($onChecklist) {
                continue;
            }

            $toLocationId = $rawLocationId ?? $defaultHomeId;
            if (! $locationMap->has($toLocationId)) {
                continue;
            }

            $item = Item::query()
                ->where('family_id', $child->family_id)
                ->where('child_id', $child->id)
                ->find($itemId);
            if ($item === null || (int) $item->location_id === $toLocationId) {
                continue;
            }

            $loc = $locationMap->get($toLocationId);
            $eventType = $loc->kind === ItemLocationKind::Home && $checklist->kind === ItemChecklistKind::AfterSchool
                ? ItemEventType::BroughtHome
                : ItemEventType::Moved;

            $this->events->record(
                $item,
                $eventType,
                $actor,
                $toLocationId,
                ItemStatus::Normal,
                null,
            );
        }
    }

    /**
     * @return array{xp: int, coins: int}|null
     */
    private function grantCompletionReward(ItemChecklist $checklist, ChildProfile $child): ?array
    {
        $policyCode = $checklist->kind === ItemChecklistKind::BeforeSchool
            ? 'item.checklist.before_school'
            : 'item.checklist.after_school';

        $policy = RewardPolicy::query()->firstOrCreate(
            ['family_id' => $child->family_id, 'code' => $policyCode],
            [
                'xp_amount' => 5,
                'coin_amount' => 2,
                'requires_approval' => false,
                'max_per_day' => 10,
                'is_active' => true,
            ],
        );
        if (! $policy->is_active || $policy->requires_approval) {
            return null;
        }

        $grantKey = 'item:checklist:'.$checklist->id;
        if (RewardGrant::query()->where('grant_key', $grantKey)->exists()) {
            return null;
        }

        $grant = $this->rewards->grant(new RewardIntent(
            familyId: $child->family_id,
            childId: $child->id,
            policyCode: $policyCode,
            grantKey: $grantKey,
            sourceModule: SourceModule::Item,
            sourceType: 'item_checklist',
            sourceId: $checklist->id,
            occurredAt: $this->clock->now(),
        ));

        if ($grant->xp_amount <= 0 && $grant->coin_amount <= 0) {
            return null;
        }

        return ['xp' => $grant->xp_amount, 'coins' => $grant->coin_amount];
    }

    private function assertOpen(ItemChecklist $checklist): void
    {
        if (! $checklist->isOpen()) {
            abort(409, '清单已完成核对');
        }
    }

    private function locationId(int $familyId, ItemLocationKind $kind): int
    {
        $location = ItemLocation::query()
            ->where('family_id', $familyId)
            ->where('kind', $kind)
            ->first();

        if ($location !== null) {
            return $location->id;
        }

        $seeded = $this->locations->seedForFamily($familyId);
        foreach ($seeded as $loc) {
            if ($loc->kind === $kind) {
                return $loc->id;
            }
        }

        return $this->locations->defaultHomeLocationId($familyId);
    }
}
