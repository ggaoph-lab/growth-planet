<?php

namespace App\Modules\Food\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodExplorationRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_date' => 'date',
            'looked' => 'boolean',
            'smelled' => 'boolean',
            'touched' => 'boolean',
            'tasted_voluntarily' => 'boolean',
            'ate' => 'boolean',
            'said_tasty' => 'boolean',
            'willing_again' => 'boolean',
        ];
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
