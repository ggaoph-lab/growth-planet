<?php

namespace App\Modules\Shop\Models;

use App\Modules\Core\Models\Attachment;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reward extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => RewardKind::class,
            'status' => RewardStatus::class,
            'requires_approval' => 'boolean',
            'coin_price' => 'integer',
            'point_price' => 'integer',
        ];
    }

    public function pricedInPoints(): bool
    {
        return (int) ($this->point_price ?? 0) > 0;
    }

    public function priceAmount(): int
    {
        return $this->pricedInPoints()
            ? (int) ($this->point_price ?? 0)
            : (int) ($this->coin_price ?? 0);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'image_attachment_id');
    }
}
