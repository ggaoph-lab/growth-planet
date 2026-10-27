<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryWeeklyRecommendation extends Model
{
    protected $table = 'poetry_weekly_recommendations';

    protected $fillable = [
        'family_id',
        'child_id',
        'poem_id',
        'week_key',
        'age_years',
        'grade',
        'reason',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
