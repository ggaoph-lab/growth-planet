<?php

namespace App\Modules\Schedule\DTO;

use App\Modules\Schedule\Models\ScheduleException;

/**
 * @phpstan-type PeriodShape array{period_index: int, subject: string, start_time: ?string, end_time: ?string, room: ?string}
 */
final class ResolvedDaySchedule
{
    /**
     * @param  list<PeriodShape>  $periods
     * @param  list<string>  $subjects
     */
    public function __construct(
        public readonly string $date,
        public readonly bool $isSchoolDay,
        public readonly array $periods,
        public readonly array $subjects,
        public readonly ?ScheduleException $exception = null,
        public readonly ?int $scheduleId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'is_school_day' => $this->isSchoolDay,
            'periods' => $this->periods,
            'subjects' => $this->subjects,
            'schedule_id' => $this->scheduleId,
            'exception' => $this->exception === null ? null : [
                'id' => $this->exception->id,
                'type' => $this->exception->type->value,
                'follow_weekday' => $this->exception->follow_weekday,
                'note' => $this->exception->note,
                'child_id' => $this->exception->child_id,
            ],
        ];
    }
}
