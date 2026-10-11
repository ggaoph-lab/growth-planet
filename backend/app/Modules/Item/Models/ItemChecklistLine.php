<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemChecklistLineResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemChecklistLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => ItemChecklistLineResult::class,
            'checked_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ItemChecklist::class, 'item_checklist_id');
    }
}
