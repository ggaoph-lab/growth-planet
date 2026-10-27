<?php

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class DeductionPreset extends Model
{
    protected $table = 'ledger_deduction_presets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'point_amount' => 'integer',
            'coin_amount' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
