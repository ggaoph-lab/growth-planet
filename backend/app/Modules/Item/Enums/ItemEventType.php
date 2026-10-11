<?php

namespace App\Modules\Item\Enums;

enum ItemEventType: string
{
    case Moved = 'moved';
    case Checked = 'checked';
    case ReportedMissing = 'reported_missing';
    case Found = 'found';
    case MarkedLost = 'marked_lost';
    case Damaged = 'damaged';
    case Correction = 'correction';
}
