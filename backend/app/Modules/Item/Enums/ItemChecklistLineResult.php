<?php

namespace App\Modules\Item\Enums;

enum ItemChecklistLineResult: string
{
    case Pending = 'pending';
    case Present = 'present';
    case NotFound = 'not_found';
    case LeftAtSchool = 'left_at_school';
    case NotNeeded = 'not_needed';
}
