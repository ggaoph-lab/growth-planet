<?php

namespace App\Modules\Shop\Models;

use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reward extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => RewardKind::class,
            'status' => RewardStatus::class,
            'requires_approval' => 'boolean',
        ];
    }
}
