<?php

namespace App\Modules\ScreenTime\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenTimePolicy extends Model
{
    protected $fillable = [
        'family_id',
        'child_id',
        'category',
        'period',
        'minutes',
        'warn_before_minutes',
        'carry_over',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'carry_over' => 'boolean',
            'effective_from' => 'date',
        ];
    }
}
