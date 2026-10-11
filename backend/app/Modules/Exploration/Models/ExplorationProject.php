<?php

namespace App\Modules\Exploration\Models;

use App\Modules\Exploration\Enums\ExplorationCategory;
use App\Modules\Exploration\Enums\ExplorationProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExplorationProject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => ExplorationCategory::class,
            'status' => ExplorationProjectStatus::class,
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExplorationEvent::class, 'project_id');
    }
}
