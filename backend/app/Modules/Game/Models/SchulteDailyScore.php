<?php

namespace App\Modules\Game\Models;

use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $family_id
 * @property int $child_id
 * @property string $played_on
 * @property int $size
 * @property int $best_ms
 * @property int $coins_awarded
 * @property int|null $reward_grant_id
 * @property-read ChildProfile|null $child
 */
class SchulteDailyScore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'played_on' => 'date',
            'size' => 'integer',
            'best_ms' => 'integer',
            'coins_awarded' => 'integer',
        ];
    }

    /** @return BelongsTo<ChildProfile, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_id');
    }
}
