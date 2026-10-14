<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemChecklistKind;
use App\Modules\Item\Enums\ItemChecklistStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemChecklist extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ItemChecklistKind::class,
            'status' => ItemChecklistStatus::class,
            'target_date' => 'date',
            'generated_from' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === ItemChecklistStatus::Open;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemChecklistLine::class);
    }
}
