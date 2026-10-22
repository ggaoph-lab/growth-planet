<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XpAccount extends Model
{
    public $timestamps = false;

    protected $fillable = ['family_id', 'child_id', 'level', 'balance', 'lifetime_earned', 'lock_version', 'updated_at'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'balance' => 'integer',
            'lifetime_earned' => 'integer',
            'lock_version' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_id');
    }
}
