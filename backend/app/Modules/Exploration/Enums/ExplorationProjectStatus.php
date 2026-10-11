<?php

namespace App\Modules\Exploration\Enums;

enum ExplorationProjectStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
