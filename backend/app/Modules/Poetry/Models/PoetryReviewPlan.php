<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryReviewPlan extends Model
{
    protected $table = 'poetry_review_plans';

    protected $fillable = [
        'family_id',
        'child_id',
        'poem_id',
        'anchor_date',
        'step_index',
        'next_review_date',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'anchor_date' => 'date',
            'next_review_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
