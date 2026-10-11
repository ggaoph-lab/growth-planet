<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieWatchSession extends Model
{
    protected $fillable = [
        'library_id',
        'child_id',
        'started_at',
        'ended_at',
        'measured_seconds',
        'counted_seconds',
        'entry_method',
        'position_start',
        'position_end',
        'anomaly',
        'confirmation',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChildMovieLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(ChildMovieLibrary::class, 'library_id');
    }
}
