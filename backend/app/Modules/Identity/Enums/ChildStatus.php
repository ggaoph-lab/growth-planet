<?php

namespace App\Modules\Identity\Enums;

enum ChildStatus: string
{
    /** Can log in and use the app. */
    case Active = 'active';
    /** Temporarily cannot log in; data kept. */
    case Paused = 'paused';
    /** Hidden from login and lists; data kept. */
    case Archived = 'archived';
}
