<?php

namespace App\Modules\Task\Enums;

enum ScheduleFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Dates = 'dates';
    case Once = 'once';
}
