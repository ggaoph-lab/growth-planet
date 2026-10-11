<?php

namespace App\Modules\Shop\Enums;

enum RedemptionStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Fulfilled = 'fulfilled';
}
