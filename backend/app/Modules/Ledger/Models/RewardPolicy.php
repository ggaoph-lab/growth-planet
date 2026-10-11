<?php

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class RewardPolicy extends Model
{
    protected $fillable = [
        'family_id', 'code', 'xp_amount', 'coin_amount', 'requires_approval',
        'max_per_event', 'max_per_day', 'max_per_week', 'max_per_subject', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'xp_amount' => 'integer',
            'coin_amount' => 'integer',
            'requires_approval' => 'boolean',
            'max_per_event' => 'integer',
            'max_per_day' => 'integer',
            'max_per_week' => 'integer',
            'max_per_subject' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
