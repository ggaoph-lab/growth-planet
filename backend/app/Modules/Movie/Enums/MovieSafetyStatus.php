<?php

namespace App\Modules\Movie\Enums;

enum MovieSafetyStatus: string
{
    case ApprovedSuitable = 'APPROVED_SUITABLE';
    case Pending = 'PENDING';
    case Unsuitable = 'UNSUITABLE';
    case InsufficientInfo = 'INSUFFICIENT_INFO';
    case ParentBanned = 'PARENT_BANNED';
}
