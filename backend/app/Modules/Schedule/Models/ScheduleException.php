<?php

namespace App\Modules\Schedule\Models;

use App\Modules\Schedule\Enums\ScheduleExceptionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleException extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'exception_date' => 'date',
            'type' => ScheduleExceptionType::class,
            'follow_weekday' => 'integer',
            'custom_periods' => 'array',
        ];
    }
}
