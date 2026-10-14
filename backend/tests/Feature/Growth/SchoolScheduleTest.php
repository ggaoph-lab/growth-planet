<?php

namespace Tests\Feature\Growth;

use App\Modules\Schedule\Models\SchoolSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_parent_can_put_and_get_weekly_schedule(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)
            ->putJson("/api/v1/parent/children/{$child->id}/school-schedule", [
                'title' => '一年级课表',
                'period_count' => 6,
                'effective_from' => '2026-09-01',
                'periods' => [
                    ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
                    ['weekday' => 1, 'period_index' => 2, 'subject' => '数学'],
                    ['weekday' => 3, 'period_index' => 1, 'subject' => '英语'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.title', '一年级课表')
            ->assertJsonPath('data.period_count', 6)
            ->assertJsonCount(3, 'data.periods');

        $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/children/{$child->id}/school-schedule")
            ->assertOk()
            ->assertJsonPath('data.periods.0.subject', '语文');
    }

    public function test_parent_resolve_day_preview(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)->putJson("/api/v1/parent/children/{$child->id}/school-schedule", [
            'effective_from' => '2026-09-01',
            'periods' => [
                ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
            ],
        ])->assertOk();

        $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/children/{$child->id}/schedule?date=2026-10-12")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', true)
            ->assertJsonPath('data.subjects.0', '语文');
    }

    public function test_schedule_exceptions_crud(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/schedule-exceptions', [
                'child_id' => $child->id,
                'exception_date' => '2026-10-01',
                'type' => 'no_school',
                'note' => '国庆',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsParent($parent)
            ->putJson("/api/v1/parent/schedule-exceptions/{$created['id']}", [
                'type' => 'makeup_day',
                'follow_weekday' => 5,
                'note' => '调休上班',
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'makeup_day')
            ->assertJsonPath('data.follow_weekday', 5);

        $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/schedule-exceptions?child_id={$child->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsParent($parent)
            ->deleteJson("/api/v1/parent/schedule-exceptions/{$created['id']}")
            ->assertOk();

        $this->assertSoftDeleted('schedule_exceptions', ['id' => $created['id']]);
    }

    public function test_subject_and_extra_requirements(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $item = $this->seedItem($family, $child, ['name' => '数学课本', 'carry_daily' => false]);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/subject-item-requirements', [
                'child_id' => $child->id,
                'subject' => '数学',
                'item_id' => $item->id,
            ])
            ->assertCreated();

        $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/subject-item-requirements?child_id={$child->id}")
            ->assertOk()
            ->assertJsonPath('data.0.subject', '数学');

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/bag-extra-requirements', [
                'child_id' => $child->id,
                'target_date' => '2026-10-13',
                'label' => '雨伞',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('bag_extra_requirements', [
            'child_id' => $child->id,
            'label' => '雨伞',
        ]);
    }

    public function test_kid_can_read_today_and_week(): void
    {
        CarbonImmutable::setTestNow('2026-10-12 02:00:00 UTC'); // Monday Shanghai
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        app(\App\Modules\Schedule\Services\SchoolScheduleService::class)->putWeekly(
            $child,
            '本学期课表',
            6,
            '2026-09-01',
            null,
            [
                ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
                ['weekday' => 1, 'period_index' => 2, 'subject' => '数学'],
                ['weekday' => 2, 'period_index' => 1, 'subject' => '英语'],
            ],
        );

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/schedule')
            ->assertOk()
            ->assertJsonPath('data.today.is_school_day', true)
            ->assertJsonPath('data.today.subjects.0', '语文')
            ->assertJsonPath('data.tomorrow.subjects.0', '英语');

        $this->actingAsKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/schedule/week')
            ->assertOk()
            ->assertJsonCount(3, 'data.periods');
    }

    public function test_cross_family_cannot_read_schedule(): void
    {
        ['family' => $familyA] = $this->makeFamily('mama_a');
        ['parent' => $parentB] = $this->makeFamily('mama_b');
        $childA = $this->makeChild($familyA);

        SchoolSchedule::query()->create([
            'family_id' => $familyA->id,
            'child_id' => $childA->id,
            'title' => '课表',
            'period_count' => 6,
            'effective_from' => '2026-09-01',
        ]);

        $this->actingAsParent($parentB)
            ->getJson("/api/v1/parent/children/{$childA->id}/school-schedule")
            ->assertNotFound();
    }
}
