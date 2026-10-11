<?php

namespace App\Modules\Item\Enums;

enum ItemStatus: string
{
    case Normal = 'normal';
    case TemporarilyMissing = 'temporarily_missing';
    case Lost = 'lost';
    case Found = 'found';
    case Damaged = 'damaged';
}
