<?php

namespace App\Modules\Movie\Models;

use App\Modules\Movie\Enums\CollectionSortMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $family_id
 * @property string $source
 * @property string|null $external_id
 * @property string $name
 * @property string|null $overview
 * @property string $sort_mode
 * @property int $movie_count
 * @property bool $structure_locked
 * @property bool $name_locked
 * @property bool $overview_locked
 * @property bool $poster_locked
 */
class MovieCollection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'family_id',
        'source',
        'external_id',
        'name',
        'overview',
        'poster_attachment_id',
        'sort_mode',
        'movie_count',
        'structure_locked',
        'name_locked',
        'overview_locked',
        'poster_locked',
        'tmdb_snapshot',
        'tmdb_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'movie_count' => 'integer',
            'structure_locked' => 'boolean',
            'name_locked' => 'boolean',
            'overview_locked' => 'boolean',
            'poster_locked' => 'boolean',
            'tmdb_snapshot' => 'array',
            'tmdb_synced_at' => 'datetime',
            'sort_mode' => CollectionSortMode::class,
        ];
    }

    /** @return HasMany<MovieCollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MovieCollectionItem::class, 'collection_id');
    }

    /** @return HasMany<MovieCollectionPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(MovieCollectionPreference::class, 'collection_id');
    }

    public function positionColumn(): string
    {
        return match ($this->sort_mode instanceof CollectionSortMode
            ? $this->sort_mode
            : CollectionSortMode::from((string) $this->sort_mode)) {
            CollectionSortMode::Release => 'position_release',
            CollectionSortMode::Custom => 'position_custom',
            CollectionSortMode::Story => 'position_story',
        };
    }
}
