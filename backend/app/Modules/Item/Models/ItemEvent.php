<?php

namespace App\Modules\Item\Models;

use App\Modules\Item\Enums\ItemEventType;
use App\Modules\Item\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ItemEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_type' => ItemEventType::class,
            'from_status' => ItemStatus::class,
            'to_status' => ItemStatus::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('item_events is append-only'));
        static::deleting(fn () => throw new LogicException('item_events is append-only'));
    }
}
