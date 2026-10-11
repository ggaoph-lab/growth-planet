<?php

namespace App\Modules\Task\Models;

use App\Modules\Task\Enums\ScheduleFrequency;
use Illuminate\Database\Eloquent\Model;

class TaskSchedule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'frequency' => ScheduleFrequency::class,
            'weekdays' => 'array',
            'dates' => 'array',
        ];
    }
}
