<?php

namespace App\Modules\Approval\Enums;

enum ApprovalStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Returned = 'RETURNED';
    case Cancelled = 'CANCELLED';
}
