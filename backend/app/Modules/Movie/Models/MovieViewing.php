<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pass through a movie. The first four completed passes can earn points.
 *
 * @property int $id
 * @property int $library_id
 * @property int $sequence
 * @property string $language
 * @property int $position_percent
 * @property string $status
 * @property bool $rewarded
 * @property int|null $reward_grant_id
 */
class MovieViewing extends Model
{
    public const REWARDABLE_COMPLETIONS = 4;

    public const COMPLETE_PERCENT = 90;

    protected $fillable = [
        'library_id',
        'sequence',
        'language',
        'position_percent',
        'status',
        'completed_at',
        'rewarded',
        'reward_grant_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'position_percent' => 'integer',
            'rewarded' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChildMovieLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(ChildMovieLibrary::class, 'library_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toKidArray(): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'language' => $this->language,
            'position_percent' => $this->position_percent,
            'status' => $this->status,
            'reward_eligible' => $this->sequence <= self::REWARDABLE_COMPLETIONS && ! $this->rewarded,
            'rewarded' => $this->rewarded,
        ];
    }
}
