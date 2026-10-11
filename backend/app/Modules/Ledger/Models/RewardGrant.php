<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Enums\SourceModule;
use Illuminate\Database\Eloquent\Model;

class RewardGrant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'xp_amount' => 'integer',
            'coin_amount' => 'integer',
            'status' => RewardGrantStatus::class,
            'source_module' => SourceModule::class,
            'occurred_at' => 'datetime',
            'cap_detail' => 'array',
        ];
    }
}
