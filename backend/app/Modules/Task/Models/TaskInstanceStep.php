<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;

class TaskInstanceStep extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_self_check' => 'boolean',
            'checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
