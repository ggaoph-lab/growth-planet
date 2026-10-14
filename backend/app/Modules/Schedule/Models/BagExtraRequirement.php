<?php

namespace App\Modules\Schedule\Models;

use App\Modules\Item\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BagExtraRequirement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'quantity' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
