<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\CoinHoldStatus;
use Illuminate\Database\Eloquent\Model;

class CoinHold extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => CoinHoldStatus::class,
        ];
    }
}
