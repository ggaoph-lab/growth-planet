<?php

namespace Tests\Feature\Growth;

use App\Modules\Item\Enums\ItemChecklistKind;
use App\Modules\Item\Enums\ItemChecklistLineResult;
use App\Modules\Item\Enums\ItemChecklistStatus;
use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Models\ItemChecklist;
use App\Modules\Item\Models\ItemEvent;
use App\Modules\Item\Models\ItemLocation;
use App\Modules\Item\Services\ItemChecklistService;
use App\Modules\Item\Services\ItemEventRecorder;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Schedule\Enums\ScheduleExceptionType;
use App\Modules\Schedule\Models\BagExtraRequirement;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\SchoolPeriod;
use App\Modules\Schedule\Models\SchoolSchedule;
use App\Modules\Schedule\Models\SubjectItemRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemLifeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

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

    public function test_kid_can_add_brought_item_on_after_school_checklist(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $child);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $afterSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::AfterSchool)
            ->firstOrFail();

        $payload = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/brought-items", [
                'name' => '学校发的手工',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('学校发的手工', $payload['item']['name']);
        $this->assertSame(ItemChecklistLineResult::Present->value, $payload['line']['result']);

        $item = Item::query()->findOrFail($payload['item']['id']);
        $home = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Home)
            ->firstOrFail();
        $this->assertSame($home->id, $item->location_id);

        $event = ItemEvent::query()->where('item_id', $item->id)->latest('id')->firstOrFail();
        $this->assertSame(ItemEventType::BroughtHome, $event->event_type);
        $this->assertSame('从学校带回', $event->note);
    }

    public function test_kid_can_add_packed_item_on_before_school_checklist(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $child);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $beforeSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->firstOrFail();

        $payload = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/brought-items", [
                'name' => '明天要用的画具',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('明天要用的画具', $payload['item']['name']);
        $this->assertSame(ItemChecklistLineResult::Present->value, $payload['line']['result']);

        $item = Item::query()->findOrFail($payload['item']['id']);
        $bag = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Bag)
            ->firstOrFail();
        $this->assertSame($bag->id, $item->location_id);

        $event = ItemEvent::query()->where('item_id', $item->id)->latest('id')->firstOrFail();
        $this->assertSame(ItemEventType::Checked, $event->event_type);
        $this->assertSame('放入书包', $event->note);
    }

    public function test_kid_can_view_own_item_flow_events(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $item = $this->seedItem($family, $child);
        $bag = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Bag)
            ->firstOrFail();

        app(ItemEventRecorder::class)->record(
            $item,
            ItemEventType::Moved,
            $parent,
            $bag->id,
        );

        $events = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson("/api/v1/kid/items/{$item->id}/events")
            ->assertOk()
            ->json('data.events');

        $this->assertNotEmpty($events);
        $this->assertSame('moved', $events[0]['event_type']);
        $this->assertSame('书包', $events[0]['to_location']['name']);
    }

    public function test_kid_cannot_view_sibling_item_events(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $childA = $this->makeChild($family, ['nickname' => 'A']);
        $childB = $this->makeChild($family, ['nickname' => 'B', 'pin' => '5678']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $itemB = $this->seedItem($family, $childB, ['name' => 'B 的橡皮']);

        $this->actingAsKid($childA->fresh(['member.user']), $device, $token)
            ->getJson("/api/v1/kid/items/{$itemB->id}/events")
            ->assertNotFound();
    }

    public function test_active_checklists_use_tomorrow_for_before_school_and_today_for_after_school(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedItem($family, $child);

        $checklists = app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $before = $checklists->firstWhere('kind', ItemChecklistKind::BeforeSchool);
        $after = $checklists->firstWhere('kind', ItemChecklistKind::AfterSchool);

        $this->assertNotNull($before);
        $this->assertNotNull($after);

        $tz = $family->timezone;
        $today = now('UTC')->setTimezone($tz)->toDateString();
        $tomorrow = now('UTC')->setTimezone($tz)->addDay()->toDateString();

        $this->assertSame($tomorrow, $before->target_date->toDateString());
        $this->assertSame($today, $after->target_date->toDateString());
    }

    public function test_kid_can_complete_checklist_when_all_lines_checked(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $child);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $afterSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::AfterSchool)
            ->with('lines')
            ->firstOrFail();
        $line = $afterSchool->lines->firstOrFail();

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/lines/{$line->id}", [
                'result' => ItemChecklistLineResult::Present->value,
            ])
            ->assertOk();

        $payload = $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->json('data');

        $this->assertSame(5, $payload['reward']['xp']);
        $this->assertSame(2, $payload['reward']['coins']);
        $this->assertDatabaseHas('reward_grants', [
            'grant_key' => 'item:checklist:'.$afterSchool->id,
            'policy_code' => 'item.checklist.after_school',
            'xp_amount' => 5,
            'coin_amount' => 2,
        ]);

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.reward', null);

        $this->assertSame(1, RewardGrant::query()->where('grant_key', 'item:checklist:'.$afterSchool->id)->count());

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/lines/{$line->id}", [
                'result' => ItemChecklistLineResult::LeftAtSchool->value,
            ])
            ->assertStatus(409);
    }

    public function test_checklist_complete_respects_inactive_reward_policy(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $child);

        RewardPolicy::query()
            ->where('family_id', $family->id)
            ->where('code', 'item.checklist.before_school')
            ->update(['is_active' => false]);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $beforeSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->with('lines')
            ->firstOrFail();
        $line = $beforeSchool->lines->firstOrFail();

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/lines/{$line->id}", [
                'result' => ItemChecklistLineResult::Present->value,
            ])
            ->assertOk();

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->assertJsonPath('data.reward', null);

        $this->assertDatabaseMissing('reward_grants', [
            'grant_key' => 'item:checklist:'.$beforeSchool->id,
        ]);
    }

    public function test_parent_can_update_item_checklist_reward_policies(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/reward-policies')
            ->assertOk()
            ->assertJsonFragment(['code' => 'item.checklist.before_school'])
            ->assertJsonFragment(['code' => 'item.checklist.after_school']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/reward-policies', [
                'code' => 'item.checklist.before_school',
                'xp_amount' => 8,
                'coin_amount' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.xp_amount', 8)
            ->assertJsonPath('data.coin_amount', 3);
    }

    public function test_kid_cannot_complete_checklist_with_pending_lines(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedItem($family, $child);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $afterSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::AfterSchool)
            ->firstOrFail();

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/complete")
            ->assertStatus(422);
    }

    public function test_kid_can_complete_checklist_with_box_placements(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $itemA = $this->seedItem($family, $child, ['name' => 'A']);
        $itemB = $this->seedItem($family, $child, ['name' => 'B']);
        $itemC = $this->seedItem($family, $child, ['name' => 'C']);

        $bag = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Bag)
            ->firstOrFail();
        $home = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Home)
            ->firstOrFail();
        $school = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::School)
            ->firstOrFail();

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $beforeSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->firstOrFail();

        // Morning: A,B,C → bag; complete once for reward
        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/complete", [
                'placements' => [
                    ['item_id' => $itemA->id, 'location_id' => $bag->id],
                    ['item_id' => $itemB->id, 'location_id' => $bag->id],
                    ['item_id' => $itemC->id, 'location_id' => $bag->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->assertJsonPath('data.reward.xp', 5);

        $this->assertSame($bag->id, $itemA->fresh()->location_id);
        $this->assertSame($bag->id, $itemB->fresh()->location_id);
        $this->assertSame($bag->id, $itemC->fresh()->location_id);

        $afterSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::AfterSchool)
            ->firstOrFail();

        // After school: A,B → home; C → school
        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$afterSchool->id}/complete", [
                'placements' => [
                    ['item_id' => $itemA->id, 'location_id' => $home->id],
                    ['item_id' => $itemB->id, 'location_id' => $home->id],
                    ['item_id' => $itemC->id, 'location_id' => $school->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->assertJsonPath('data.reward.coins', 2);

        $this->assertSame($home->id, $itemA->fresh()->location_id);
        $this->assertSame($home->id, $itemB->fresh()->location_id);
        $this->assertSame($school->id, $itemC->fresh()->location_id);
    }

    public function test_kid_can_reopen_a_completed_checklist_without_a_second_reward(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $item = $this->seedItem($family, $child, ['name' => '铅笔']);

        $home = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Home)
            ->firstOrFail();
        $bag = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Bag)
            ->firstOrFail();

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);
        $beforeSchool = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->firstOrFail();

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/complete", [
                'placements' => [
                    ['item_id' => $item->id, 'location_id' => $bag->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->assertJsonPath('data.reward.coins', 2);

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'open')
            ->assertJsonPath('data.checklist.lines.0.result', 'pending');

        $this->assertSame($bag->id, $item->fresh()->location_id);

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/item-checklists/{$beforeSchool->id}/complete", [
                'placements' => [
                    ['item_id' => $item->id, 'location_id' => $home->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.checklist.status', 'completed')
            ->assertJsonPath('data.reward', null);

        $this->assertSame($home->id, $item->fresh()->location_id);
        $this->assertSame(1, RewardGrant::query()->where('grant_key', 'item:checklist:'.$beforeSchool->id)->count());
    }

    public function test_parent_can_add_and_remove_home_and_bag_boxes(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $created = $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/item-locations', [
                'name' => '奶奶家',
                'kind' => 'home',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('home', $created['kind']);
        $this->assertFalse($created['is_system']);

        $bagBox = $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/item-locations', [
                'name' => '体育袋',
                'kind' => 'bag',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsParent($parent)
            ->deleteJson("/api/v1/parent/item-locations/{$bagBox['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('item_locations', ['id' => $bagBox['id']]);

        $systemHome = ItemLocation::query()
            ->where('family_id', $family->id)
            ->where('kind', ItemLocationKind::Home)
            ->where('is_system', true)
            ->firstOrFail();

        $this->actingAsParent($parent)
            ->deleteJson("/api/v1/parent/item-locations/{$systemHome->id}")
            ->assertStatus(422);
    }

    public function test_checklist_merges_subject_daily_and_extra_items(): void
    {
        // Monday Shanghai → tomorrow Tuesday
        CarbonImmutable::setTestNow('2026-10-12 02:00:00 UTC');
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        $daily = $this->seedItem($family, $child, ['name' => '水杯', 'carry_daily' => true]);
        $mathBook = $this->seedItem($family, $child, ['name' => '数学课本', 'carry_daily' => false]);
        $umbrella = $this->seedItem($family, $child, ['name' => '雨伞', 'carry_daily' => false]);

        $schedule = SchoolSchedule::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '课表',
            'period_count' => 6,
            'effective_from' => '2026-09-01',
        ]);
        // Tomorrow 2026-10-13 is Tuesday (weekday 2)
        SchoolPeriod::query()->create([
            'school_schedule_id' => $schedule->id,
            'weekday' => 2,
            'period_index' => 1,
            'subject' => '数学',
        ]);

        SubjectItemRequirement::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'subject' => '数学',
            'item_id' => $mathBook->id,
            'quantity' => 1,
        ]);
        BagExtraRequirement::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'target_date' => '2026-10-13',
            'item_id' => $umbrella->id,
        ]);

        app(ItemChecklistService::class)->ensureTodayChecklists($child, $family);

        $before = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->whereDate('target_date', '2026-10-13')
            ->with('lines')
            ->firstOrFail();

        $itemIds = $before->lines->pluck('item_id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$daily->id, $mathBook->id, $umbrella->id], $itemIds);
    }

    public function test_checklist_regen_keeps_confirmed_and_marks_not_needed(): void
    {
        CarbonImmutable::setTestNow('2026-10-12 02:00:00 UTC');
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        $daily = $this->seedItem($family, $child, ['name' => '水杯', 'carry_daily' => true]);
        $mathBook = $this->seedItem($family, $child, ['name' => '数学课本', 'carry_daily' => false]);

        $schedule = SchoolSchedule::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '课表',
            'period_count' => 6,
            'effective_from' => '2026-09-01',
        ]);
        SchoolPeriod::query()->create([
            'school_schedule_id' => $schedule->id,
            'weekday' => 2,
            'period_index' => 1,
            'subject' => '数学',
        ]);
        SubjectItemRequirement::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'subject' => '数学',
            'item_id' => $mathBook->id,
        ]);

        $svc = app(ItemChecklistService::class);
        $svc->ensureTodayChecklists($child, $family);

        $before = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->whereDate('target_date', '2026-10-13')
            ->with('lines')
            ->firstOrFail();

        $mathLine = $before->lines->firstWhere('item_id', $mathBook->id);
        $mathLine->result = ItemChecklistLineResult::Present;
        $mathLine->save();

        // Remove math period → regen should keep present math line, mark daily still pending, math stays present
        SchoolPeriod::query()->where('school_schedule_id', $schedule->id)->delete();
        SchoolPeriod::query()->create([
            'school_schedule_id' => $schedule->id,
            'weekday' => 2,
            'period_index' => 1,
            'subject' => '语文',
        ]);

        $svc->ensureTodayChecklists($child, $family);
        $before->refresh()->load('lines');

        $this->assertSame(
            ItemChecklistLineResult::Present,
            $before->lines->firstWhere('item_id', $mathBook->id)->result,
        );
        $this->assertSame(
            ItemChecklistLineResult::Pending,
            $before->lines->firstWhere('item_id', $daily->id)->result,
        );
    }

    public function test_no_school_cancels_open_checklist(): void
    {
        CarbonImmutable::setTestNow('2026-10-12 02:00:00 UTC');
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedItem($family, $child);

        $schedule = SchoolSchedule::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '课表',
            'period_count' => 6,
            'effective_from' => '2026-09-01',
        ]);
        SchoolPeriod::query()->create([
            'school_schedule_id' => $schedule->id,
            'weekday' => 2,
            'period_index' => 1,
            'subject' => '数学',
        ]);

        $svc = app(ItemChecklistService::class);
        $svc->ensureTodayChecklists($child, $family);

        $before = ItemChecklist::query()
            ->where('child_id', $child->id)
            ->where('kind', ItemChecklistKind::BeforeSchool)
            ->whereDate('target_date', '2026-10-13')
            ->firstOrFail();
        $this->assertSame(ItemChecklistStatus::Open, $before->status);

        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => null,
            'exception_date' => '2026-10-13',
            'type' => ScheduleExceptionType::NoSchool,
        ]);

        $result = $svc->ensureTodayChecklists($child, $family);
        $this->assertFalse($result->contains(fn ($c) => $c->kind === ItemChecklistKind::BeforeSchool));
        $this->assertSame(ItemChecklistStatus::Cancelled, $before->fresh()->status);
    }
}
