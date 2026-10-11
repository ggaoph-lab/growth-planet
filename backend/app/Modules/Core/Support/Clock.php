<?php

namespace App\Modules\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Time is stored in UTC; business dates, weeks and ages are computed in the family's timezone.
 */
class Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /** Current calendar date (Y-m-d) in the given timezone. */
    public function today(string $timezone): CarbonImmutable
    {
        return $this->now()->setTimezone($timezone)->startOfDay();
    }

    /**
     * Week containing $date in $timezone, Monday 00:00 to Sunday 23:59:59.999999, returned in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function weekRange(string $timezone, ?DateTimeInterface $at = null): array
    {
        $local = CarbonImmutable::instance($at ?? $this->now())->setTimezone($timezone);
        $start = $local->startOfWeek(CarbonImmutable::MONDAY);
        $end = $local->endOfWeek(CarbonImmutable::SUNDAY);

        return [$start->utc(), $end->utc()];
    }

    /** Full years between $birthDate (Y-m-d) and $onDate (Y-m-d); Feb 29 birthdays advance on Mar 1 in non-leap years. */
    public function ageOn(string $birthDate, string $onDate): int
    {
        $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthDate, 'UTC');
        $on = CarbonImmutable::createFromFormat('!Y-m-d', $onDate, 'UTC');

        $age = $on->year - $birth->year;
        if ($on->format('md') < $birth->format('md')) {
            $age--;
        }

        return max(0, $age);
    }

    public function ageToday(string $birthDate, string $timezone): int
    {
        return $this->ageOn($birthDate, $this->today($timezone)->toDateString());
    }

    /** ISO 8601 with the family's offset, e.g. 2026-10-11T19:30:00+08:00. */
    public static function iso(?DateTimeInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->toIso8601String();
    }
}
