<?php

namespace App\Modules\English\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnglishWatchLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'family_id',
        'child_id',
        'show_id',
        'watch_date',
        'minutes',
        'self_chosen',
        'liking',
        'words_learned',
        'status',
        'reviewed_by',
        'reviewed_at',
        'parent_note',
        'approved_minutes',
        'approval_request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'watch_date' => 'date',
            'self_chosen' => 'boolean',
            'words_learned' => 'array',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnglishShow, $this> */
    public function show(): BelongsTo
    {
        return $this->belongsTo(EnglishShow::class, 'show_id');
    }
}
