<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;

class TaskSubmission extends Model
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
