<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryAssignment extends Model
{
    protected $table = 'poetry_assignments';

    protected $fillable = [
        'family_id',
        'child_id',
        'poem_id',
        'assigned_by',
        'due_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
