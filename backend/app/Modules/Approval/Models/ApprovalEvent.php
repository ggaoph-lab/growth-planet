<?php

namespace App\Modules\Approval\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
