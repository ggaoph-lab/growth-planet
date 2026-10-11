<?php

namespace App\Modules\ScreenTime\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenTimeEntry extends Model
{
    protected $fillable = [
        'child_id',
        'category',
        'source_type',
        'source_id',
        'period_start',
        'seconds',
        'entry_method',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
        ];
    }
}
