<?php

namespace App\Modules\Task\Models;

use App\Modules\Task\Enums\TaskKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'requires_approval' => 'boolean',
            'requires_self_check' => 'boolean',
            'with_parent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('sort');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TaskSchedule::class);
    }
}
