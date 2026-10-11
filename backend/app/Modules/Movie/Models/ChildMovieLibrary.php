<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChildMovieLibrary extends Model
{
    protected $table = 'child_movie_library';

    protected $fillable = [
        'family_id',
        'child_id',
        'movie_id',
        'shelf',
        'slot',
        'slot_active_key',
        'ever_completed',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'ever_completed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /** @return HasOne<MovieProgress, $this> */
    public function progress(): HasOne
    {
        return $this->hasOne(MovieProgress::class, 'library_id');
    }

    /** @return HasMany<MovieViewing, $this> */
    public function viewings(): HasMany
    {
        return $this->hasMany(MovieViewing::class, 'library_id')->orderBy('sequence');
    }
}
