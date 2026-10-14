<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'period_index' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SchoolSchedule::class, 'school_schedule_id');
    }
}
