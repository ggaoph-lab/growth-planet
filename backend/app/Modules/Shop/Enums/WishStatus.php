<?php

namespace App\Modules\Shop\Enums;

enum WishStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Converted = 'converted';
}
