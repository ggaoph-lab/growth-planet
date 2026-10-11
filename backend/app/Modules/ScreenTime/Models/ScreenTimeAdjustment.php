<?php

namespace App\Modules\ScreenTime\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenTimeAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'category',
        'period_start',
        'kind',
        'seconds',
        'reason',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
