<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemChecklistKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemChecklist extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ItemChecklistKind::class,
            'target_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemChecklistLine::class);
    }
}
