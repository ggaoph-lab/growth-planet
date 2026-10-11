<?php

namespace Tests\Unit;

use App\Modules\Core\Support\Clock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_today_uses_family_timezone_not_utc(): void
    {
        // 16:30 UTC on Sunday is already 00:30 Monday in Shanghai.
        CarbonImmutable::setTestNow('2026-10-11 16:30:00 UTC');
        $clock = new Clock;

        $this->assertSame('2026-10-12', $clock->today('Asia/Shanghai')->toDateString());
        $this->assertSame('2026-10-11', $clock->today('UTC')->toDateString());
        $this->assertSame('2026-10-11', $clock->today('America/Los_Angeles')->toDateString());
    }

    public function test_week_runs_monday_to_sunday_in_family_timezone(): void
    {
        $clock = new Clock;

        [$start, $end] = $clock->weekRange('Asia/Shanghai', CarbonImmutable::parse('2026-10-11 15:59:59 UTC'));
        // Sunday 23:59:59 Shanghai still belongs to the week starting Monday 2026-10-05.
        $this->assertSame('2026-10-04T16:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-10-11 15:59:59', $end->format('Y-m-d H:i:s'));

        [$nextStart] = $clock->weekRange('Asia/Shanghai', CarbonImmutable::parse('2026-10-11 16:00:00 UTC'));
        $this->assertSame('2026-10-11T16:00:00+00:00', $nextStart->toIso8601String());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function ages(): array
    {
        return [
            'day before birthday' => ['2019-10-12', '2026-10-11', 6],
            'on birthday' => ['2019-10-11', '2026-10-11', 7],
            'leap day, non-leap year before Mar 1' => ['2020-02-29', '2027-02-28', 6],
            'leap day, non-leap year on Mar 1' => ['2020-02-29', '2027-03-01', 7],
            'leap day, leap year' => ['2020-02-29', '2028-02-29', 8],
            'born today' => ['2026-10-11', '2026-10-11', 0],
        ];
    }

    #[DataProvider('ages')]
    public function test_age_on(string $birth, string $on, int $expected): void
    {
        $this->assertSame($expected, (new Clock)->ageOn($birth, $on));
    }

    public function test_age_today_changes_at_midnight_in_family_timezone(): void
    {
        $clock = new Clock;

        // Birthday is 2019-10-12. At 15:59 UTC on Oct 11 it is still Oct 11 in Shanghai.
        CarbonImmutable::setTestNow('2026-10-11 15:59:00 UTC');
        $this->assertSame(6, $clock->ageToday('2019-10-12', 'Asia/Shanghai'));

        CarbonImmutable::setTestNow('2026-10-11 16:00:00 UTC');
        $this->assertSame(7, $clock->ageToday('2019-10-12', 'Asia/Shanghai'));
        $this->assertSame(6, $clock->ageToday('2019-10-12', 'UTC'));
    }

    public function test_iso_formats_with_family_offset(): void
    {
        $this->assertSame(
            '2026-10-11T19:30:00+08:00',
            Clock::iso(CarbonImmutable::parse('2026-10-11 11:30:00 UTC'), 'Asia/Shanghai'),
        );
        $this->assertNull(Clock::iso(null, 'Asia/Shanghai'));
    }
}
