<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemLocationKind;
use Illuminate\Database\Eloquent\Model;

class ItemLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ItemLocationKind::class,
            'is_system' => 'boolean',
        ];
    }
}
