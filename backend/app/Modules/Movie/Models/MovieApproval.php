<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieApproval extends Model
{
    protected $fillable = [
        'movie_id',
        'child_id',
        'visible',
        'approved_by',
        'approved_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
