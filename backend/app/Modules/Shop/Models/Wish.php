<?php

namespace App\Modules\Shop\Models;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Shop\Enums\WishStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wish extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'coin_price' => 'integer',
            'status' => WishStatus::class,
        ];
    }

    /** @return BelongsTo<ChildProfile, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_id');
    }

    /** @return BelongsTo<Reward, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
