<?php

namespace App\Modules\Exploration\Models;

use App\Modules\Exploration\Enums\WillingNext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExplorationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'willing_next' => WillingNext::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ExplorationProject::class, 'project_id');
    }
}
