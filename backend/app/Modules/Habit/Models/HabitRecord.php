<?php

namespace App\Modules\Habit\Models;

use Illuminate\Database\Eloquent\Model;

class HabitRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
