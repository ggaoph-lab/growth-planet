<?php

namespace Tests\Unit;

use App\Modules\Schedule\Enums\ScheduleExceptionType;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\SchoolPeriod;
use App\Modules\Schedule\Models\SchoolSchedule;
use App\Modules\Schedule\Services\ScheduleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_school_exception_yields_empty_day(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedWeekly($family->id, $child->id, '2026-09-01');

        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => null,
            'exception_date' => '2026-10-01',
            'type' => ScheduleExceptionType::NoSchool,
            'note' => '国庆',
        ]);

        $resolved = app(ScheduleResolver::class)->resolve($child, $family, '2026-10-01');
        $this->assertFalse($resolved->isSchoolDay);
        $this->assertSame([], $resolved->subjects);
    }

    public function test_makeup_day_follows_weekday(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedWeekly($family->id, $child->id, '2026-09-01', [
            // Friday = 5 → 数学
            ['weekday' => 5, 'period_index' => 1, 'subject' => '数学'],
            ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
        ]);

        // 2026-10-10 is Saturday; makeup as Friday
        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => null,
            'exception_date' => '2026-10-10',
            'type' => ScheduleExceptionType::MakeupDay,
            'follow_weekday' => 5,
        ]);

        $resolved = app(ScheduleResolver::class)->resolve($child, $family, '2026-10-10');
        $this->assertTrue($resolved->isSchoolDay);
        $this->assertSame(['数学'], $resolved->subjects);
    }

    public function test_custom_exception_uses_temp_periods(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedWeekly($family->id, $child->id, '2026-09-01');

        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'exception_date' => '2026-10-20',
            'type' => ScheduleExceptionType::Custom,
            'custom_periods' => [
                ['period_index' => 1, 'subject' => '语文'],
                ['period_index' => 2, 'subject' => '语文'],
                ['period_index' => 3, 'subject' => '体育'],
                ['period_index' => 4, 'subject' => '秋游'],
            ],
        ]);

        $resolved = app(ScheduleResolver::class)->resolve($child, $family, '2026-10-20');
        $this->assertTrue($resolved->isSchoolDay);
        $this->assertSame(['语文', '体育', '秋游'], $resolved->subjects);
        $this->assertCount(4, $resolved->periods);
    }

    public function test_child_exception_overrides_family(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedWeekly($family->id, $child->id, '2026-09-01');

        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => null,
            'exception_date' => '2026-10-15',
            'type' => ScheduleExceptionType::NoSchool,
            'note' => '全家放假',
        ]);
        ScheduleException::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'exception_date' => '2026-10-15',
            'type' => ScheduleExceptionType::Custom,
            'custom_periods' => [
                ['period_index' => 1, 'subject' => '补课'],
            ],
        ]);

        $resolved = app(ScheduleResolver::class)->resolve($child, $family, '2026-10-15');
        $this->assertTrue($resolved->isSchoolDay);
        $this->assertSame(['补课'], $resolved->subjects);
    }

    public function test_effective_range_picks_correct_schedule(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        $old = SchoolSchedule::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '上学期',
            'period_count' => 6,
            'effective_from' => '2026-02-01',
            'effective_to' => '2026-07-31',
        ]);
        SchoolPeriod::query()->create([
            'school_schedule_id' => $old->id,
            'weekday' => 1,
            'period_index' => 1,
            'subject' => '旧语文',
        ]);

        $new = SchoolSchedule::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '下学期',
            'period_count' => 6,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ]);
        SchoolPeriod::query()->create([
            'school_schedule_id' => $new->id,
            'weekday' => 1,
            'period_index' => 1,
            'subject' => '新语文',
        ]);

        $resolver = app(ScheduleResolver::class);
        // 2026-10-12 is Monday
        $this->assertSame(['新语文'], $resolver->resolve($child, $family, '2026-10-12')->subjects);
        // 2026-03-02 is Monday
        $this->assertSame(['旧语文'], $resolver->resolve($child, $family, '2026-03-02')->subjects);
    }

    public function test_weekend_without_periods_is_not_school_day(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedWeekly($family->id, $child->id, '2026-09-01', [
            ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
        ]);

        // 2026-10-11 is Sunday
        $resolved = app(ScheduleResolver::class)->resolve($child, $family, '2026-10-11');
        $this->assertFalse($resolved->isSchoolDay);
    }

    /**
     * @param  list<array{weekday: int, period_index: int, subject: string}>  $periods
     */
    private function seedWeekly(int $familyId, int $childId, string $from, array $periods = []): SchoolSchedule
    {
        $schedule = SchoolSchedule::query()->create([
            'family_id' => $familyId,
            'child_id' => $childId,
            'title' => '本学期课表',
            'period_count' => 6,
            'effective_from' => $from,
            'effective_to' => null,
        ]);

        $default = $periods !== [] ? $periods : [
            ['weekday' => 1, 'period_index' => 1, 'subject' => '语文'],
            ['weekday' => 1, 'period_index' => 2, 'subject' => '数学'],
            ['weekday' => 2, 'period_index' => 1, 'subject' => '英语'],
            ['weekday' => 5, 'period_index' => 1, 'subject' => '体育'],
        ];

        foreach ($default as $row) {
            SchoolPeriod::query()->create([
                'school_schedule_id' => $schedule->id,
                ...$row,
            ]);
        }

        return $schedule;
    }
}
