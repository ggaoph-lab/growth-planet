<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'carry_daily' => 'boolean',
            'location_confirmed_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ItemLocation::class, 'location_id');
    }
}
