<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $family_id
 * @property int $collection_id
 * @property int $movie_id
 * @property int|null $position_story
 * @property int|null $position_release
 * @property int|null $position_custom
 * @property string $membership_source
 */
class MovieCollectionItem extends Model
{
    protected $fillable = [
        'family_id',
        'collection_id',
        'movie_id',
        'position_story',
        'position_release',
        'position_custom',
        'membership_source',
    ];

    protected function casts(): array
    {
        return [
            'position_story' => 'integer',
            'position_release' => 'integer',
            'position_custom' => 'integer',
        ];
    }

    /** @return BelongsTo<MovieCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(MovieCollection::class, 'collection_id');
    }

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
