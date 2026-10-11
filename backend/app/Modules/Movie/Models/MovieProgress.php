<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieProgress extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'library_id';

    public $timestamps = false;

    protected $fillable = [
        'library_id',
        'position_seconds',
        'duration_seconds',
        'status',
        'first_started_at',
        'completed_at',
        'last_updated_at',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'first_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChildMovieLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(ChildMovieLibrary::class, 'library_id');
    }
}
