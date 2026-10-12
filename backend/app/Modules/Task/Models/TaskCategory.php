<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskCategory extends Model
{
    protected $guarded = [];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'category_id');
    }
}
