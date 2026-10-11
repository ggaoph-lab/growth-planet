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
            'status' => RedemptionStatus::class,
        ];
    }
}
