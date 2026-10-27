<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryRecitationRecord extends Model
{
    protected $table = 'poetry_recitation_records';

    protected $fillable = [
        'family_id',
        'child_id',
        'poem_id',
        'kind',
        'transcript',
        'passed',
        'accuracy_percent',
        'diff',
        'feedback',
        'points_awarded',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'bool',
            'diff' => 'array',
        ];
    }

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
