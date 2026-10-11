<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $family_id
 * @property string $source
 * @property string|null $external_id
 * @property string $title_zh
 * @property string|null $original_title
 * @property string $data_completeness
 * @property int|null $current_review_id
 */
class Movie extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'family_id',
        'source',
        'external_id',
        'title_zh',
        'original_title',
        'poster_key',
        'release_year',
        'runtime_minutes',
        'genres',
        'overview',
        'certifications',
        'tmdb_adult',
        'data_completeness',
        'attribution',
        'current_review_id',
    ];

    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'certifications' => 'array',
            'tmdb_adult' => 'boolean',
        ];
    }

    /** @return BelongsTo<MovieAgeReview, $this> */
    public function currentReview(): BelongsTo
    {
        return $this->belongsTo(MovieAgeReview::class, 'current_review_id');
    }

    /** @return HasMany<MovieApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(MovieApproval::class);
    }

    /** @return HasOne<MovieCollectionItem, $this> */
    public function collectionItem(): HasOne
    {
        return $this->hasOne(MovieCollectionItem::class);
    }
}
