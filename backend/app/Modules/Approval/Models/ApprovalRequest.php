<?php

namespace App\Modules\Approval\Models;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class);
    }

    public function activeKeyForPending(): string
    {
        return $this->type->value.':'.$this->subject_type.':'.$this->subject_id;
    }
}
