<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\PointHoldStatus;
use Illuminate\Database\Eloquent\Model;

class PointHold extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PointHoldStatus::class,
        ];
    }
}
