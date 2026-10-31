<?php

namespace App\Modules\Shop\Models;

use App\Modules\Shop\Enums\RedemptionStatus;
use Illuminate\Database\Eloquent\Model;

class RewardRedemption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reward_snapshot' => 'array',
            'fulfilled_at' => 'datetime',
            'status' => RedemptionStatus::class,
        ];
    }
}
