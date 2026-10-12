<?php

namespace App\Modules\Task\Models;

use App\Modules\Task\Enums\TaskKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function taskCategory(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('sort');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TaskSchedule::class);
    }

    /**
     * Frozen copy stored on a task instance.
     *
     * @return array<string, mixed>
     */
    public function instanceSnapshot(): array
    {
        $this->loadMissing(['schedules', 'steps', 'parent']);

        return [
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'xp_reward' => $this->xp_reward,
            'coin_reward' => $this->coin_reward,
            'requires_approval' => $this->requires_approval,
            'requires_self_check' => $this->requires_self_check,
            'with_parent' => $this->with_parent,
            'due_time' => $this->schedules->first()?->due_time,
            'group_title' => $this->parent?->title,
            'group_task_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'steps' => $this->steps->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'is_required' => $s->is_required,
                'is_self_check' => $s->is_self_check,
                'sort' => $s->sort,
            ])->values()->all(),
        ];
    }
}
