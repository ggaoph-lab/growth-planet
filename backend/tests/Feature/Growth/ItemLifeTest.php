<?php

namespace Tests\Feature\Growth;

use App\Modules\Item\Enums\ItemChecklistLineResult;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemLifeTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_move_item_records_event(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $item = $this->seedItem($family, $child);
        $bag = ItemLocation::query()->where('family_id', $family->id)->where('kind', ItemLocationKind::Bag)->firstOrFail();

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/items/{$item->id}/events", [
                'event_type' => 'moved',
                'to_location_id' => $bag->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.location_id', $bag->id);

        $this->assertSame($bag->id, $item->fresh()->location_id);
    }

    public function test_cross_family_cannot_move_item(): void
    {
        ['family' => $familyA, 'parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB, 'parent' => $parentB] = $this->makeFamily('mama_b');
        $childA = $this->makeChild($familyA);
        $item = $this->seedItem($familyA, $childA);
        $bagB = ItemLocation::query()->where('family_id', $familyB->id)->where('kind', ItemLocationKind::Bag)->firstOrFail();

        $this->actingAsParent($parentB)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/items/{$item->id}/events", [
                'event_type' => 'moved',
                'to_location_id' => $bagB->id,
            ])
            ->assertNotFound();
    }

    public function test_kid_checklist_line_is_scoped_to_own_child(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $childA = $this->makeChild($family, ['nickname' => 'A']);
        $childB = $this->makeChild($family, ['nickname' => 'B', 'pin' => '5678']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $childA);
        $this->seedItem($family, $childB);

        app(ItemChecklistService::class)->ensureTodayChecklists($childA, $family);
        $checklistA = ItemChecklist::query()->where('child_id', $childA->id)->with('lines')->firstOrFail();
        $lineA = $checklistA->lines->firstOrFail();

        $this->actingAsKid($childB->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$checklistA->id}/lines/{$lineA->id}", [
                'result' => ItemChecklistLineResult::Present->value,
            ])
            ->assertNotFound();
    }

    public function test_kid_bag_only_shows_own_items(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $childA = $this->makeChild($family, ['nickname' => 'A']);
        $childB = $this->makeChild($family, ['nickname' => 'B', 'pin' => '5678']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $childA, ['name' => 'A 的水杯']);
        $this->seedItem($family, $childB, ['name' => 'B 的水杯']);

        $items = $this->loginKid($childA->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/bag')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('A 的水杯', $items[0]['name']);
    }
}
