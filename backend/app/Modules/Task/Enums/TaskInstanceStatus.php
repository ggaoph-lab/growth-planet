<?php

namespace App\Modules\Task\Enums;

enum TaskInstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
