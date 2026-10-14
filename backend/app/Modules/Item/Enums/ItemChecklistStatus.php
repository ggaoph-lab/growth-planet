<?php

namespace App\Modules\Item\Enums;

enum ItemChecklistStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case CompletedWithIssues = 'completed_with_issues';
    case Cancelled = 'cancelled';
}
