<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $child_id
 * @property int $library_id
 * @property string $kind
 * @property int|null $previous_library_id
 * @property int|null $reward_grant_id
 */
class MovieRewardEvent extends Model
{
    protected $fillable = [
        'child_id',
        'library_id',
        'kind',
        'occurred_at',
        'week_start',
        'previous_library_id',
        'reward_grant_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'week_start' => 'date',
        ];
    }

    /** @return BelongsTo<ChildMovieLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(ChildMovieLibrary::class, 'library_id');
    }
}
