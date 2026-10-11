<?php

namespace App\Modules\Task\Models;

use App\Modules\Task\Enums\TaskInstanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskInstance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'task_date' => 'date',
            'snapshot' => 'array',
            'status' => TaskInstanceStatus::class,
            'ready_at' => 'datetime',
            'first_step_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'self_checked' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function instanceSteps(): HasMany
    {
        return $this->hasMany(TaskInstanceStep::class)->orderBy('sort');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }
}
