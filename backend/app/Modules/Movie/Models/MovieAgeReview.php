<?php

namespace App\Modules\Movie\Models;

use App\Modules\Movie\Enums\MovieSafetyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieAgeReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'movie_id',
        'safety_status',
        'min_age',
        'max_age',
        'reason',
        'reviewer_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'safety_status' => MovieSafetyStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
